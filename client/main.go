// Der Starter übergibt den t2med-Kontext einmal per HTTPS und beendet sich,
// nachdem er den Standardbrowser geöffnet hat. Kein lokaler Server/Dienst.
package main

import (
	"bytes"
	"crypto/sha256"
	"crypto/tls"
	"crypto/x509"
	"encoding/hex"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"strings"
	"time"
)

type Config struct {
	ServerURL         string `json:"server_url"`
	LauncherKey       string `json:"launcher_key"`
	CertificateSHA256 string `json:"certificate_sha256"`
}

func main() {
	configPath := flag.String("config", "", "Pfad zur Clientkonfiguration")
	deepLink := flag.String("url", "", "t2med-Aufruf")
	version := flag.Bool("version", false, "Version anzeigen")
	flag.Parse()
	if *version {
		fmt.Println("kienzle-sumup 1.3")
		return
	}
	if *configPath == "" {
		exe, err := os.Executable()
		if err != nil {
			fail("Starterpfad nicht verfügbar.")
		}
		*configPath = filepath.Join(filepath.Dir(exe), "client.json")
	}
	raw, err := os.ReadFile(*configPath)
	if err != nil {
		fail("Clientkonfiguration fehlt. Bitte Client-Installer ausführen.")
	}
	var cfg Config
	if json.Unmarshal(raw, &cfg) != nil {
		fail("Clientkonfiguration ist ungültig.")
	}
	if *deepLink == "" && flag.NArg() == 1 {
		*deepLink = flag.Arg(0)
	}
	if *deepLink == "" {
		fail("Bitte Kienzle-SumUp aus der Patientenakte in T2med öffnen.")
	}
	target, err := handoff(cfg, *deepLink)
	if err != nil {
		fail(err.Error())
	}
	if err = openBrowser(target); err != nil {
		fail("Der Browser konnte nicht geöffnet werden. Bitte den Standardbrowser einrichten.")
	}
}

func parseLink(link string) (map[string]string, error) {
	if len(link) > 24000 {
		return nil, errors.New("t2med-Aufruf ist zu lang.")
	}
	u, err := url.Parse(link)
	if err != nil || strings.ToLower(u.Scheme) != "kienzle-sumup" || u.User != nil || u.Fragment != "" {
		return nil, errors.New("Ungültiger Kienzle-SumUp-Aufruf.")
	}
	query, err := url.ParseQuery(u.RawQuery)
	if err != nil {
		return nil, errors.New("Ungültige Aufrufparameter.")
	}
	fields := map[string]string{"kontextId": "context_id", "fhirBasisUrl": "fhir_base_url", "oAuthToken": "oauth_token"}
	data := map[string]string{"action": "launch"}
	for from, to := range fields {
		values := query[from]
		if len(values) != 1 || strings.TrimSpace(values[0]) == "" {
			return nil, errors.New("t2med-Aufruf ist unvollständig.")
		}
		for _, r := range values[0] {
			if r < 32 || r == 127 {
				return nil, errors.New("Ungültige Aufrufparameter.")
			}
		}
		data[to] = values[0]
	}
	return data, nil
}

func handoff(cfg Config, link string) (string, error) {
	base, err := url.Parse(cfg.ServerURL)
	if err != nil || base.Scheme != "https" || base.Hostname() == "" || base.User != nil || base.RawQuery != "" || base.Fragment != "" || (base.Path != "" && base.Path != "/") {
		return "", errors.New("Serveradresse muss HTTPS ohne zusätzlichen Pfad verwenden.")
	}
	key, err := hex.DecodeString(cfg.LauncherKey)
	if err != nil || len(key) != 32 {
		return "", errors.New("Ungültiger Starter-Schlüssel.")
	}
	pin, err := hex.DecodeString(cfg.CertificateSHA256)
	if err != nil || len(pin) != 32 {
		return "", errors.New("Zertifikat-Fingerabdruck fehlt.")
	}
	data, err := parseLink(link)
	if err != nil {
		return "", err
	}
	payload, _ := json.Marshal(data)
	// Die Prüfung erfolgt gegen das bei der Einrichtung übertragene exakte
	// Zertifikat einschließlich Hostname und Gültigkeit; kein Trust-on-first-use.
	tlsConfig := &tls.Config{MinVersion: tls.VersionTLS12, InsecureSkipVerify: true,
		VerifyConnection: func(state tls.ConnectionState) error {
			if len(state.PeerCertificates) == 0 {
				return errors.New("Zertifikat fehlt")
			}
			cert := state.PeerCertificates[0]
			sum := sha256.Sum256(cert.Raw)
			if !bytes.Equal(pin, sum[:]) {
				return errors.New("Zertifikat stimmt nicht mit der Einrichtung überein")
			}
			now := time.Now()
			if now.Before(cert.NotBefore) || now.After(cert.NotAfter) {
				return errors.New("Zertifikat abgelaufen")
			}
			if err := cert.VerifyHostname(base.Hostname()); err != nil {
				return err
			}
			for _, use := range cert.ExtKeyUsage {
				if use == x509.ExtKeyUsageServerAuth || use == x509.ExtKeyUsageAny {
					return nil
				}
			}
			return errors.New("Zertifikat ist nicht für TLS-Server vorgesehen")
		},
	}
	client := &http.Client{Timeout: 35 * time.Second, Transport: &http.Transport{TLSClientConfig: tlsConfig, Proxy: nil}, CheckRedirect: func(req *http.Request, via []*http.Request) error { return http.ErrUseLastResponse }}
	req, err := http.NewRequest("POST", strings.TrimRight(cfg.ServerURL, "/")+"/api.php", bytes.NewReader(payload))
	if err != nil {
		return "", errors.New("Serveradresse ungültig.")
	}
	req.Header.Set("Authorization", "Bearer "+cfg.LauncherKey)
	req.Header.Set("Content-Type", "application/json")
	response, err := client.Do(req)
	if err != nil {
		return "", errors.New("Praxisserver nicht erreichbar oder Zertifikat stimmt nicht. Serveradresse und Client-Einrichtung prüfen.")
	}
	defer response.Body.Close()
	raw, err := io.ReadAll(io.LimitReader(response.Body, 32769))
	if err != nil || len(raw) > 32768 {
		return "", errors.New("Unvollständige Serverantwort.")
	}
	var result struct {
		URL   string `json:"url"`
		Error string `json:"error"`
	}
	if json.Unmarshal(raw, &result) != nil {
		return "", errors.New("Ungültige Serverantwort.")
	}
	if response.StatusCode != 200 {
		if result.Error != "" {
			return "", errors.New(result.Error)
		}
		return "", fmt.Errorf("Server meldet HTTP %d.", response.StatusCode)
	}
	target, err := url.Parse(result.URL)
	if err != nil || target.Scheme != base.Scheme || target.Host != base.Host || target.User != nil || target.Path != "/" || target.RawQuery != "" || !strings.HasPrefix(target.Fragment, "launch=") {
		return "", errors.New("Server hat einen ungültigen Startlink geliefert.")
	}
	ticket, err := hex.DecodeString(strings.TrimPrefix(target.Fragment, "launch="))
	if err != nil || len(ticket) != 32 {
		return "", errors.New("Startlink ungültig.")
	}
	return result.URL, nil
}

func openBrowser(target string) error {
	var cmd *exec.Cmd
	switch runtime.GOOS {
	case "windows":
		cmd = exec.Command("rundll32.exe", "url.dll,FileProtocolHandler", target)
	case "darwin":
		cmd = exec.Command("open", target)
	default:
		cmd = exec.Command("xdg-open", target)
	}
	return cmd.Run()
}

func fail(message string) {
	fmt.Fprintln(os.Stderr, message)
	// Nur unsere bereinigten Fehlermeldungen, niemals Deep-Link/Zugangsdaten.
	switch runtime.GOOS {
	case "windows":
		script := "Add-Type -AssemblyName PresentationFramework; [System.Windows.MessageBox]::Show($env:KS_STARTER_ERROR,'Kienzle-SumUp') | Out-Null"
		cmd := exec.Command("powershell.exe", "-NoProfile", "-NonInteractive", "-Command", script)
		cmd.Env = append(os.Environ(), "KS_STARTER_ERROR="+message)
		_ = cmd.Run()
	case "darwin":
		cmd := exec.Command("osascript", "-e", "on run argv", "-e", "display alert \"Kienzle-SumUp\" message (item 1 of argv)", "-e", "end run", message)
		_ = cmd.Run()
	default:
		if path, err := exec.LookPath("zenity"); err == nil {
			_ = exec.Command(path, "--error", "--title=Kienzle-SumUp", "--text="+message).Run()
		}
	}
	os.Exit(1)
}

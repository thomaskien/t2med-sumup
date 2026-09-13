package main

import (
 "strings"
 "testing"
)

func TestLaunchParameters(t *testing.T) {
 data,err:=parseLink("kienzle-sumup://?kontextId=case-1&fhirBasisUrl=https%3A%2F%2Ft2med.test%2Faps%2Ffhir%2Fapi%2Fr4&oAuthToken=a%2Bb%3D")
 if err!=nil || data["oauth_token"]!="a+b=" || data["context_id"]!="case-1" {t.Fatal("Kontext oder Token verändert",err)}
 for _,link:=range []string{
  "kienzledoku://?kontextId=case-1", "kienzle-sumup://?kontextId=1&kontextId=2&fhirBasisUrl=https://x&oAuthToken=x",
  "kienzle-sumup://?kontextId=1&fhirBasisUrl=https://x&oAuthToken=a%0Ab",
 } {if _,err:=parseLink(link);err==nil {t.Fatal("Ungültiger Link akzeptiert")}}
 _,err=handoff(Config{ServerURL:"http://server",LauncherKey:strings.Repeat("a",64)},"kienzle-sumup://")
 if err==nil {t.Fatal("Unsicheres Serverziel akzeptiert")}
}

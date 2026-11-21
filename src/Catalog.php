<?php
declare(strict_types=1);
namespace KienzleSumup;

/** Leistungskatalog: einzelne (GOÄ-)Leistungen und Kombinationen anlegen, ändern und löschen. Gemeinsam für Kassenseite und Adminpanel. */
final class Catalog {
    public function __construct(private Database $db, private Config $config) {}

    /** Katalog für die Verwaltung im Adminpanel. */
    public function overview(): array {
        $services = array_map(static fn($s) => ['vat' => (bool)$s['vat'], 'on_request' => (bool)$s['on_request']] + $s,
            $this->db->query('SELECT id,label,price_cents,goae_code,factor,fee_type,on_request,vat,base_cents,default_reason FROM services WHERE active=1 ORDER BY label COLLATE NOCASE')->fetchAll());
        $groups = array_map(static fn($g) => ['id' => $g['id'], 'label' => $g['label'], 'services' => json_decode($g['service_ids_json'], true, 32, JSON_THROW_ON_ERROR)],
            $this->db->query('SELECT * FROM service_groups WHERE active=1 ORDER BY label COLLATE NOCASE')->fetchAll());
        return ['services' => $services, 'groups' => $groups, 'fee_types' => ServiceCatalog::FEES,
            'tax' => array_intersect_key(Tax::settings($this->config), ['small_business' => 1, 'rate' => 1, 'manual_vat' => 1]),
            'max_amount_cents' => $this->config->get('app', 'max_amount_cents')];
    }

    public function saveService(array $input): void {
        $id = App::string($input, 'id', 32, false);
        $label = App::string($input, 'label', 160);
        $cents = Money::cents($input['price'] ?? '', $this->config->get('app', 'max_amount_cents'));
        $code = App::string($input, 'goae_code', 20, false);
        $type = $code !== '' ? App::string($input, 'fee_type', 16, false) ?: 'standard' : 'standard';
        $factor = $code !== '' ? ServiceCatalog::factor(App::string($input, 'factor', 10), $type) : '';
        $reason = $code !== '' ? App::string($input, 'default_reason', 800, false) : '';
        $onRequest = $input['on_request'] ?? false;
        if (!is_bool($onRequest)) throw new Problem('Ungültige Angabe für Leistung auf Verlangen.');
        $vat = $input['vat'] ?? false;
        if (!is_bool($vat)) throw new Problem('Ungültige Angabe zur Mehrwertsteuer.');
        // GOÄ-Rechner: Mit Einfachsatz muss der Preis Einfachsatz × Faktor entsprechen (±1 Cent Rundung).
        $baseInput = $code !== '' ? App::string($input, 'base', 16, false) : '';
        $base = $baseInput !== '' ? Money::cents($baseInput, $this->config->get('app', 'max_amount_cents')) : 0;
        if ($base > 0) {
            $expected = ServiceCatalog::feeUnits($base, ServiceCatalog::units($factor));
            if (abs($expected - $cents) > 1) throw new Problem('Einfachsatz × Faktor ergibt ' . Money::format($expected) . ', eingetragen sind ' . Money::format($cents) . '. Bitte den Preis anpassen oder den GOÄ-Rechner verwenden.');
        }
        if ($id === '') {
            $this->db->query('INSERT INTO services(id,label,price_cents,goae_code,factor,fee_type,on_request,vat,base_cents,default_reason,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)', [App::id(), $label, $cents, $code, $factor, $type, (int)$onRequest, (int)$vat, $base, $reason, time()]);
        } else {
            if ($this->db->query('UPDATE services SET label=?,price_cents=?,goae_code=?,factor=?,fee_type=?,on_request=?,vat=?,base_cents=?,default_reason=?,updated_at=? WHERE id=? AND active=1', [$label, $cents, $code, $factor, $type, (int)$onRequest, (int)$vat, $base, $reason, time(), $id])->rowCount() !== 1) throw new Problem('Leistung nicht gefunden.', 404);
        }
    }
    public function deleteService(string $id): void {
        $this->db->transaction(function () use ($id): void {
            foreach ($this->db->query('SELECT label,service_ids_json FROM service_groups WHERE active=1')->fetchAll() as $group) {
                if (in_array($id, json_decode($group['service_ids_json'], true), true)) throw new Problem('Bitte die Leistung zuerst aus der Kombination „' . $group['label'] . '“ entfernen.', 409);
            }
            $this->db->query('UPDATE services SET active=0,updated_at=? WHERE id=?', [time(), $id]);
        });
    }
    public function saveGroup(array $input): void {
        $id = App::string($input, 'id', 32, false); $label = App::string($input, 'label', 160);
        $ids = ServiceCatalog::ids($input['services'] ?? []);
        if (!$ids) throw new Problem('Bitte mindestens eine Einzelleistung für die Kombination wählen.');
        $this->db->transaction(function () use ($id, $label, $ids): void {
            foreach ($ids as $service) if (!$this->db->one('SELECT id FROM services WHERE id=? AND active=1', [$service])) throw new Problem('Eine enthaltene Leistung wurde gelöscht. Bitte Kombination aktualisieren.');
            $json = json_encode($ids, JSON_THROW_ON_ERROR);
            if ($id === '') $this->db->query('INSERT INTO service_groups(id,label,service_ids_json,updated_at) VALUES(?,?,?,?)', [App::id(), $label, $json, time()]);
            elseif ($this->db->query('UPDATE service_groups SET label=?,service_ids_json=?,updated_at=? WHERE id=? AND active=1', [$label, $json, time(), $id])->rowCount() !== 1) throw new Problem('Kombination nicht gefunden.', 404);
        });
    }
    public function deleteGroup(string $id): void {
        $this->db->query('UPDATE service_groups SET active=0,updated_at=? WHERE id=?', [time(), $id]);
    }
}

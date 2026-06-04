<?php
// ============================================================
//  CRYPTEX — Recherche Active Directory via LDAP
//  Supporte le bind anonyme (aucun compte de service requis)
// ============================================================

function searchADUsers(string $query, int $limit = 10): array {
    if (!LDAP_ENABLED || strlen(trim($query)) < 2) {
        return [];
    }

    $ldap = @ldap_connect(LDAP_HOST, LDAP_PORT);
    if (!$ldap) return [];

    ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($ldap, LDAP_OPT_REFERRALS, 0);
    ldap_set_option($ldap, LDAP_OPT_NETWORK_TIMEOUT, 3);

    // Bind anonyme si pas de credentials, bind authentifié sinon
    if (!empty(LDAP_BIND_USER)) {
        $bound = @ldap_bind($ldap, LDAP_BIND_USER, LDAP_BIND_PASS);
    } else {
        $bound = @ldap_bind($ldap);  // bind anonyme
    }

    if (!$bound) {
        ldap_close($ldap);
        return [];
    }

    $safe   = ldapEscape($query);
    $filter = "(&(objectClass=person)(objectCategory=user)(mail=*)"
            . "(!(userAccountControl:1.2.840.113556.1.4.803:=2))"
            . "(|(cn=*{$safe}*)(givenName=*{$safe}*)(sn=*{$safe}*)"
            . "(mail=*{$safe}*)(samaccountname=*{$safe}*)))";

    $attrs  = ['cn', 'givenName', 'sn', 'mail', 'department', 'jobtitle', 'samaccountname'];
    $result = @ldap_search($ldap, LDAP_BASE_DN, $filter, $attrs, 0, $limit);

    if (!$result) {
        ldap_close($ldap);
        return [];
    }

    $entries = ldap_get_entries($ldap, $result);
    ldap_close($ldap);

    $users = [];
    for ($i = 0; $i < $entries['count']; $i++) {
        $e = $entries[$i];
        if (empty($e['mail'][0])) continue;
        $users[] = [
            'name'       => trim(($e['givenname'][0] ?? '') . ' ' . ($e['sn'][0] ?? '')),
            'email'      => strtolower($e['mail'][0]),
            'department' => $e['department'][0] ?? '',
            'jobtitle'   => $e['jobtitle'][0]   ?? '',
            'login'      => $e['samaccountname'][0] ?? '',
        ];
    }
    return $users;
}

// ── Cron AD sync : charge tous les utilisateurs ───────────────
function getAllADUsers(int $limit = 5000): array {
    if (!LDAP_ENABLED) return [];

    $ldap = @ldap_connect(LDAP_HOST, LDAP_PORT);
    if (!$ldap) return [];

    ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($ldap, LDAP_OPT_REFERRALS, 0);
    ldap_set_option($ldap, LDAP_OPT_NETWORK_TIMEOUT, 10);

    if (!empty(LDAP_BIND_USER)) {
        $bound = @ldap_bind($ldap, LDAP_BIND_USER, LDAP_BIND_PASS);
    } else {
        $bound = @ldap_bind($ldap);
    }

    if (!$bound) {
        ldap_close($ldap);
        return [];
    }

    $filter  = '(&(objectClass=person)(objectCategory=user)(mail=*)(!(userAccountControl:1.2.840.113556.1.4.803:=2)))';
    $attrs   = ['cn', 'givenName', 'sn', 'mail', 'department', 'jobtitle',
                'samaccountname', 'officelocation', 'telephoneNumber'];
    $result  = @ldap_search($ldap, LDAP_BASE_DN, $filter, $attrs, 0, $limit);

    if (!$result) { ldap_close($ldap); return []; }

    $entries = ldap_get_entries($ldap, $result);
    ldap_close($ldap);

    $users = [];
    for ($i = 0; $i < $entries['count']; $i++) {
        $e     = $entries[$i];
        $email = strtolower(trim($e['mail'][0] ?? ''));
        $login = strtolower(trim($e['samaccountname'][0] ?? ''));
        if (!$email || !$login) continue;

        $users[] = [
            'login'     => $login,
            'email'     => $email,
            'prenom'    => $e['givenname'][0]        ?? '',
            'nom'       => $e['sn'][0]               ?? '',
            'service'   => $e['department'][0]        ?? '',
            'poste'     => $e['jobtitle'][0]          ?? '',
            'site'      => $e['officelocation'][0]    ?? '',
            'telephone' => $e['telephonenumber'][0]   ?? '',
        ];
    }
    return $users;
}

function ldapEscape(string $str): string {
    return str_replace(
        ['\\', '*', '(', ')', "\x00"],
        ['\\5c', '\\2a', '\\28', '\\29', '\\00'],
        $str
    );
}

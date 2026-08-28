<?php
/**
 * LigaHub Finance — Teste automatizado da ferramenta (PHP)
 * Valida: login individual, blindagem do financeiro (RLS) e leitura de dados.
 * Rode:  php ligahub-teste.php
 */

const SUPABASE_URL = "https://eplmfmtsaspwrywytffk.supabase.co";
const ANON_KEY = "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6ImVwbG1mbXRzYXNwd3J5d3l0ZmZrIiwicm9sZSI6ImFub24iLCJpYXQiOjE3ODI5OTY2MTYsImV4cCI6MjA5ODU3MjYxNn0.Uxak-0KGQT-hPW2QQuS6Q1yj2vwucjVx2LneNHIPypU";

// Conta de teste de cada papel (as mesmas do Word de acessos)
const DIRETOR_EMAIL = "lucas987moura@hotmail.com";  // Presidente (direcao)
const DIRETOR_SENHA = "Ligae4PSQ";
const MEMBRO_EMAIL  = "artfdmelo@gmail.com";         // Arthur (membro)
const MEMBRO_SENHA  = "LigaRyYxM";

$PASS = 0; $FAIL = 0;

function req($method, $path, $headers, $body = null) {
    $ch = curl_init(SUPABASE_URL . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 20,
        // Windows CLI as vezes nao tem CA bundle configurado; desliga verificacao so pro teste local.
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return [$code, $res, $err];
}

function login($email, $senha) {
    [$code, $res] = req("POST", "/auth/v1/token?grant_type=password",
        ["apikey: " . ANON_KEY, "Content-Type: application/json"],
        ["email" => $email, "password" => $senha]);
    $j = json_decode($res, true);
    return [$code, $j["access_token"] ?? null, $j];
}

function rest_get($path, $token = null) {
    $h = ["apikey: " . ANON_KEY, "Accept: application/json"];
    if ($token) $h[] = "Authorization: Bearer " . $token;
    else        $h[] = "Authorization: Bearer " . ANON_KEY;
    [$code, $res] = req("GET", "/rest/v1/" . $path, $h);
    return [$code, json_decode($res, true)];
}

function check($nome, $cond) {
    global $PASS, $FAIL;
    if ($cond) { $PASS++; echo "  [PASS] $nome\n"; }
    else       { $FAIL++; echo "  [FALHA] $nome\n"; }
}

echo "==============================================\n";
echo " LigaHub Finance - Teste da ferramenta\n";
echo "==============================================\n\n";

// 1) Login Direcao
echo "1) Login da Direcao (" . DIRETOR_EMAIL . ")\n";
[$c, $tokDir, $j] = login(DIRETOR_EMAIL, DIRETOR_SENHA);
check("login retorna token (HTTP $c)", $c === 200 && $tokDir);

// 2) Direcao ENXERGA o financeiro
echo "\n2) Direcao acessa o financeiro (deve conseguir)\n";
[$c, $finDir] = rest_get("liga_financeiro?select=id", $tokDir);
check("consulta financeiro responde 200 (HTTP $c)", $c === 200);
check("financeiro retorna lista (nao bloqueado)", is_array($finDir));

// 3) Login Membro
echo "\n3) Login de Membro (" . MEMBRO_EMAIL . ")\n";
[$c, $tokMem, $j] = login(MEMBRO_EMAIL, MEMBRO_SENHA);
check("login retorna token (HTTP $c)", $c === 200 && $tokMem);

// 4) Membro NAO enxerga o financeiro (BLINDAGEM)
echo "\n4) Membro tenta ver o financeiro (deve vir VAZIO)\n";
[$c, $finMem] = rest_get("liga_financeiro?select=id", $tokMem);
check("consulta responde 200 sem erro (HTTP $c)", $c === 200);
check("membro recebe 0 registros financeiros (blindado)", is_array($finMem) && count($finMem) === 0);

// 5) Anonimo (sem login) tambem nao ve financeiro
echo "\n5) Anonimo (portfolio publico) nao ve financeiro\n";
[$c, $finAnon] = rest_get("liga_financeiro?select=id", null);
check("anonimo recebe 0 registros financeiros", is_array($finAnon) && count($finAnon) === 0);

// 6) Dados operacionais carregam (pessoas)
echo "\n6) Dados da ferramenta carregam (pessoas)\n";
[$c, $pes] = rest_get("liga_pessoas?select=nome&limit=5", $tokMem);
check("lista de pessoas responde 200 (HTTP $c)", $c === 200);
check("pessoas retorna registros", is_array($pes) && count($pes) > 0);

// 7) Senha errada nao loga
echo "\n7) Senha incorreta e rejeitada\n";
[$c, $tokBad] = login(MEMBRO_EMAIL, "senha_errada_123");
check("login invalido nao retorna token (HTTP $c)", $tokBad === null);

echo "\n==============================================\n";
echo " RESULTADO: $PASS passaram, $FAIL falharam\n";
echo "==============================================\n";
exit($FAIL === 0 ? 0 : 1);

<?php
/*
 * SMTP test recipient — admin-only diagnostic.
 * Given a recipient email address, run a quick check to explain WHY it
 * might be bouncing:  domain syntax → DNS MX lookup → SMTP HELO probe.
 *
 *   POST { email: "user@domain.tld" }
 *   → JSON { ok: bool, checks: [{step, ok, label, detail}], summary }
 */
require_once __DIR__ . '/../includes/functions.php';
require_admin();
header('Content-Type: application/json');

$in    = json_decode(file_get_contents('php://input'), true) ?: ($_POST ?: $_GET);
$email = trim((string)($in['email'] ?? ''));

$checks  = [];
$overall = true;

// 1) Syntax check.
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode([
        'ok'      => false,
        'checks'  => [['step'=>'syntax', 'ok'=>false, 'label'=>'Email syntax', 'detail'=>'Not a valid RFC-5322 address.']],
        'summary' => 'The address format itself is invalid — the customer needs to give you a corrected one.',
    ]);
    exit;
}
$checks[] = ['step'=>'syntax', 'ok'=>true, 'label'=>'Email syntax', 'detail'=>'Valid format.'];

// 2) Domain + DNS A/MX lookup.
[$user, $domain] = explode('@', $email, 2);
$domain = strtolower($domain);

$hasMx = checkdnsrr($domain, 'MX');
$mxHosts = [];
$mxWeights = [];
if ($hasMx) {
    @getmxrr($domain, $mxHosts, $mxWeights);
}
$hasA = !$hasMx ? checkdnsrr($domain, 'A') : false; // RFC 5321 §5: A is implicit MX
if ($hasMx || $hasA) {
    if ($hasMx && $mxHosts) {
        // Sort by priority (lowest first)
        array_multisort($mxWeights, SORT_ASC, $mxHosts);
        $top = $mxHosts[0];
        $checks[] = ['step'=>'mx', 'ok'=>true, 'label'=>'DNS MX lookup',
                     'detail'=>"Found " . count($mxHosts) . " MX record(s). Primary: <code>{$top}</code> (priority {$mxWeights[0]})."];
    } else {
        $checks[] = ['step'=>'mx', 'ok'=>true, 'label'=>'DNS MX lookup',
                     'detail'=>"No MX records, but <code>{$domain}</code> has an A record so mail can fall back to it (RFC 5321)."];
        $mxHosts = [$domain];
    }
} else {
    $checks[] = ['step'=>'mx', 'ok'=>false, 'label'=>'DNS MX lookup',
                 'detail'=>"Domain <code>{$domain}</code> has neither MX nor A records — there's no server to deliver to. The customer's address is unreachable."];
    echo json_encode(['ok'=>false, 'checks'=>$checks,
                      'summary'=>"The address domain doesn't exist on the public Internet. Ask the customer to confirm their email — common cause is a typo (gmail.con → gmail.com)."]);
    exit;
}

// 3) Probe a TCP connection to the primary MX on port 25 (typical receive port).
//    We just look for an SMTP banner — we don't try to authenticate or send.
//    This catches "blocked by ISP/firewall" + "server down" situations.
$bannerProbe = function(string $host) : array {
    $errno = 0; $errstr = '';
    $fp = @stream_socket_client("tcp://{$host}:25", $errno, $errstr, 4.0);
    if (!$fp) return ['ok'=>false, 'error'=>$errstr ?: 'connect timeout'];
    stream_set_timeout($fp, 4);
    $banner = @fgets($fp, 1024);
    @fwrite($fp, "QUIT\r\n");
    @fclose($fp);
    return ['ok'=>(bool)$banner, 'banner'=>trim((string)$banner)];
};
$probed = false;
foreach (array_slice($mxHosts, 0, 2) as $host) {
    $r = $bannerProbe($host);
    if ($r['ok']) {
        $checks[] = ['step'=>'smtp_banner', 'ok'=>true, 'label'=>'SMTP banner probe',
                     'detail'=>"<code>{$host}</code> answered: " . htmlspecialchars(mb_substr($r['banner'], 0, 120))];
        $probed = true; break;
    }
}
if (!$probed) {
    // Network blocks (egress 25, etc.) are common on cloud hosts; don't fail hard.
    $checks[] = ['step'=>'smtp_banner', 'ok'=>null, 'label'=>'SMTP banner probe',
                 'detail'=>"Could not open port 25 to any MX from this server — common on cloud hosts (egress blocked). DNS still confirms the domain accepts mail. Use the configured SMTP relay for actual delivery."];
}

// 4) Domain reputation hints — common typos & disposable providers.
$disposable = ['mailinator.com','tempmail.com','10minutemail.com','guerrillamail.com','yopmail.com','throwawaymail.com','trashmail.com'];
$looksTypo  = ['gmail.con'=>'gmail.com','gmail.co'=>'gmail.com','gnail.com'=>'gmail.com','gemail.com'=>'gmail.com',
               'yhoo.com'=>'yahoo.com','yaho.com'=>'yahoo.com','outlok.com'=>'outlook.com','hotmial.com'=>'hotmail.com'];
if (isset($looksTypo[$domain])) {
    $checks[] = ['step'=>'reputation','ok'=>false,'label'=>'Domain hint',
                 'detail'=>"<code>{$domain}</code> looks like a typo of <code>{$looksTypo[$domain]}</code> — likely the real reason this bounced."];
    $overall = false;
} elseif (in_array($domain, $disposable, true)) {
    $checks[] = ['step'=>'reputation','ok'=>false,'label'=>'Domain hint',
                 'detail'=>"<code>{$domain}</code> is a disposable / throw-away inbox provider — many real customers can't actually receive licence keys here."];
}

// Final pass: if every required step is OK, declare deliverable.
$failedSteps = array_filter($checks, fn($c) => $c['ok'] === false);
$ok = empty($failedSteps);
$summary = $ok
    ? "Address looks deliverable. If it still bounces, the issue is at the recipient's mailbox (full inbox, content rejected by spam filter, alias forwarded to a closed account). Try resending from a different sender domain or ask the customer for an alternate address."
    : "Found likely cause — see flagged step(s) above.";

echo json_encode(['ok'=>$ok, 'checks'=>$checks, 'summary'=>$summary]);

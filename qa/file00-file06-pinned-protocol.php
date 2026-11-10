<?php
/** Fail-closed owner/consumer mismatch ledger for pinned File06 v12 source. */
declare(strict_types=1);
$root = getenv('FILE06_SOURCE_ROOT');
if (!$root) { fwrite(STDERR, "Missing immutable File06 checkout\n"); exit(2); }
$consumer = file_get_contents($root.'/homeopathy-encyclopedia/includes/class-he-v12-completion.php');
$owner = file_get_contents(dirname(__DIR__).'/source/sabri-membership-core/includes/class-smc-file06-case-consent.php');
if (!is_string($consumer) || !is_string($owner)) { exit(2); }
$begin = strpos($consumer, 'public static function consent_attestation(');
if ($begin === false) { fwrite(STDERR, "File06 consent provider entry point changed\n"); exit(1); }
$method = substr($consumer, $begin);
$checks = array(
  'File06 verification hook is present' => strpos($method, "he_v12_consent_receipt_verification_v1") !== false,
  'File06 provides policy evidence' => strpos($method, "'evidence_ref' => $receipt") !== false,
  'File00 requires research UUID' => strpos($owner, "$data['research_public_id']") !== false,
  'File06 currently omits required UUID' => strpos($method, "'research_public_id' =>") === false,
);
foreach ($checks as $name => $ok) {
  if (!$ok) { fwrite(STDERR, "FAIL: {$name}\n"); exit(1); }
  echo "PASS: {$name}\n";
}
echo "BLOCKED: File06 candidate must send the verified research UUID. Never authorize an unbound patient-case receipt.\n";

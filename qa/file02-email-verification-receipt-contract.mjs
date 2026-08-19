import fs from 'node:fs';

const auth = fs.readFileSync('source/sabri-membership-core/includes/class-smc-authentication-contract.php', 'utf8');
const contracts = fs.readFileSync('source/sabri-membership-core/includes/class-smc-contracts.php', 'utf8');

function assert(condition, message) {
  if (!condition) {
    throw new Error(message);
  }
}

assert(
  auth.includes("'email_verification' !== $purpose") && auth.includes("email_verification_context_invalid"),
  'File 00 must reject unscoped File 02 email-verification handoffs.'
);
assert(
  auth.includes("'file02-email-verification|' . $user_id . '|' . $target_hash . '|' . $idempotency_key")
    && auth.includes("'authentication-email-delivery-receipt'"),
  'File 00 must derive a privacy-minimized receipt from the exact File 02 verification attestation.'
);
assert(
  auth.includes('delivery_receipt_hash,delivered_at')
    && auth.includes('delivery_receipt_hash=VALUES(delivery_receipt_hash),delivered_at=VALUES(delivered_at)'),
  'File 00 must persist receipt-bearing delivery evidence when File 02 proves signed-link ownership.'
);
assert(
  auth.includes("SMC_Contracts::contact_verified( $user_id, 'email' )")
    && auth.includes('email_verification_receipt_unreadable'),
  'File 00 must prove the receipt is readable through its canonical contact-verification predicate before allowing completion.'
);
assert(
  contracts.includes('verified_at IS NOT NULL AND delivery_receipt_hash IS NOT NULL AND delivered_at IS NOT NULL'),
  'The canonical contact-verification reader must remain fail-closed and receipt-bearing.'
);
assert(
  !contracts.includes('verified_at IS NOT NULL LIMIT 1'),
  'The fix must not weaken contact verification to verified_at alone.'
);

console.log('File 02 signed-email-link receipt handoff contract checks passed.');

<?php
/**
 * Bitrix24 SPA Lead Deduplication Script (With Granular Logging)
 */

// 1. CONFIGURATION
define('B24_WEBHOOK_URL', 'https://b24-sgn7y5.bitrix24.in/rest/14/kdho27qenzo9pv03/'); 
define('SPA_ENTITY_TYPE_ID', 1038); 
define('TARGET_SPA_ID', 2);         

define('SPA_PHONE_FIELD', 'ufCrm8Phone'); 
define('SPA_EMAIL_FIELD', 'ufCrm8Email'); 

define('DRY_RUN', true); 

define('ACTIVITY_LOG_FILE', __DIR__ . '/dedup_activity.log');
define('JSON_PREVIEW_FILE', __DIR__ . '/b24_dedup_test_log.json');

function writeLog($message, $level = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $formattedMessage = "[$timestamp] [$level] $message" . PHP_EOL;
    echo $formattedMessage;
    file_put_contents(ACTIVITY_LOG_FILE, $formattedMessage, FILE_APPEND);
}

function callB24($method, $params = []) {
    $url = rtrim(B24_WEBHOOK_URL, '/') . '/' . $method . '.json';
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params),
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    $decoded = json_decode($response, true);
    return $decoded['result'] ?? null;
}

/**
 * Normalizes phone numbers dynamically based on whether the source number uses a '+' prefix.
 * Strips out presentation noise (spaces, hyphens, brackets).
 */
function normalizePhone($phone, $keepPlus = false) {
    $phone = trim((string)$phone);
    if ($keepPlus && strpos($phone, '+') === 0) {
        // Keep the leading '+' sign, strip everything else except digits
        return '+' . preg_replace('/[^0-9]/', '', $phone);
    }
    // Complete strip of all symbols including '+'
    return preg_replace('/[^0-9]/', '', $phone);
}

function extractMultiFields($fieldArray) {
    $values = [];
    if (is_array($fieldArray)) {
        foreach ($fieldArray as $item) {
            if (!empty($item['VALUE'])) {
                $values[] = trim((string)$item['VALUE']);
            }
        }
    }
    return $values;
}

// ==========================================
// START PIPELINE
// ==========================================
writeLog("==========================================================================");
writeLog("SCRIPT START: Initializing Lead Deduplication Engine with Strict Formatting Verification.");

$spaItem = callB24('crm.item.get', [
    'entityTypeId' => SPA_ENTITY_TYPE_ID,
    'id'           => TARGET_SPA_ID
]);

if (!$spaItem || !isset($spaItem['item'])) {
    writeLog("Critical Failure: Source SPA Item ID " . TARGET_SPA_ID . " could not be found.", 'CRITICAL');
    exit(1);
}

$spaPhone = trim((string)($spaItem['item'][SPA_PHONE_FIELD] ?? ''));
$spaEmail = trim((string)($spaItem['item'][SPA_EMAIL_FIELD] ?? ''));

if (empty($spaPhone) && empty($spaEmail)) {
    writeLog("Aborting: SPA source data fields are completely empty.", 'CRITICAL');
    exit(1);
}

// Check if '+' prefix exists in the source SPA phone number
$hasPlusInSource = (strpos($spaPhone, '+') === 0);
writeLog("Format Check: Source phone prefix '+' " . ($hasPlusInSource ? "DETECTED. Preserving prefix rules." : "NOT detected. Stripping formatting prefixes globally."));

// Normalize the source number based on the detected format rule
$normSpaPhone = normalizePhone($spaPhone, $hasPlusInSource);
writeLog("Target Match Profile -> Normalized Phone Target: '$normSpaPhone' | Email Target: '$spaEmail'");

// Temporary pool for raw unverified records
$rawCandidatePool = [];

// Base search filters (We use raw values here because Bitrix24's lead.list partial search handles characters automatically)
$filterOR = ['LOGIC' => 'OR'];
if (!empty($spaPhone)) $filterOR[] = ['=PHONE' => $spaPhone];
if (!empty($spaEmail)) $filterOR[] = ['=EMAIL' => $spaEmail];

// STEP 1: Direct Leads Fetch
writeLog("Step 1: Fetching background direct matching leads.");
$directLeads = callB24('crm.lead.list', [
    'filter' => $filterOR,
    'select' => ['ID', 'TITLE', 'DATE_CREATE', 'CONTACT_ID']
]);
if (is_array($directLeads)) {
    foreach ($directLeads as $lead) {
        $rawCandidatePool[$lead['ID']] = $lead;
    }
}

// STEP 2: Relational Contacts Fetch
writeLog("Step 2: Tracking linked contact cards.");
$contacts = callB24('crm.contact.list', [
    'filter' => $filterOR,
    'select' => ['ID']
]);

if (!empty($contacts) && is_array($contacts)) {
    $contactIds = array_column($contacts, 'ID');
    $contactLeads = callB24('crm.lead.list', [
        'filter' => ['=CONTACT_ID' => $contactIds],
        'select' => ['ID', 'TITLE', 'DATE_CREATE', 'CONTACT_ID']
    ]);
    if (is_array($contactLeads)) {
        foreach ($contactLeads as $lead) {
            $rawCandidatePool[$lead['ID']] = $lead;
        }
    }
}

// ==========================================
// STEP 3: STRICT FORMAT-SAFE VALIDATION
// ==========================================
writeLog("Step 3: Beginning deep format-safe validation on " . count($rawCandidatePool) . " candidates...");
$verifiedLeads = [];

foreach ($rawCandidatePool as $id => $lead) {
    writeLog("Deep inspecting Lead ID #$id...", 'DEBUG');
    
    $fullLead = callB24('crm.lead.get', ['id' => $id]);
    $leadPhones = extractMultiFields($fullLead['PHONE'] ?? null);
    $leadEmails = extractMultiFields($fullLead['EMAIL'] ?? null);
    
    $contactPhones = [];
    $contactEmails = [];
    
    if (!empty($lead['CONTACT_ID']) && (int)$lead['CONTACT_ID'] > 0) {
        $fullContact = callB24('crm.contact.get', ['id' => $lead['CONTACT_ID']]);
        if ($fullContact) {
            $contactPhones = extractMultiFields($fullContact['PHONE'] ?? null);
            $contactEmails = extractMultiFields($fullContact['EMAIL'] ?? null);
        }
    }
    
    // Normalize candidates strictly following the rule set by the SPA source data
    $allAssociatedPhones = [];
    foreach (array_merge($leadPhones, $contactPhones) as $rawPhoneNum) {
        $allAssociatedPhones[] = normalizePhone($rawPhoneNum, $hasPlusInSource);
    }
    
    $allAssociatedEmails = array_map('strtolower', array_merge($leadEmails, $contactEmails));
    
    // EXECUTE COMPARISON
    $hasPhoneMatch = (!empty($normSpaPhone) && in_array($normSpaPhone, $allAssociatedPhones, true));
    $hasEmailMatch = (!empty($spaEmail) && in_array(strtolower($spaEmail), $allAssociatedEmails, true));
    
    if ($hasPhoneMatch || $hasEmailMatch) {
        $verifiedLeads[$id] = [
            'ID' => $lead['ID'],
            'TITLE' => $lead['TITLE'],
            'DATE_CREATE' => $lead['DATE_CREATE'],
            'CONTACT_ID' => $lead['CONTACT_ID'],
            'EXTRACTED_DATA' => [
                'lead_phones' => $leadPhones,
                'lead_emails' => $leadEmails,
                'linked_contact_phones' => $contactPhones,
                'linked_contact_emails' => $contactEmails
            ]
        ];
        writeLog("--> PASSED: Lead #$id matches configuration criteria targets.", 'SUCCESS');
    } else {
        writeLog("--> REJECTED: Lead #$id does not pass format verification checks. Saved from deletion.", 'WARNING');
    }
}

// ==========================================
// STEP 4: DEDUPLICATION PROCESSING
// ==========================================
$totalVerifiedCount = count($verifiedLeads);
writeLog("Total verified matching leads remaining after safety review: $totalVerifiedCount");

if ($totalVerifiedCount <= 1) {
    writeLog("Clean execution completed. No duplicate conflicts verified.", 'SUCCESS');
    exit(0);
}

uasort($verifiedLeads, function($a, $b) {
    return (int)$b['ID'] - (int)$a['ID'];
});

$latestLead = array_shift($verifiedLeads);
$leadsToDelete = $verifiedLeads;

writeLog("WINNER RECORD RETAINED: ID #{$latestLead['ID']} - '{$latestLead['TITLE']}'", 'SUCCESS');
writeLog("DISPOSAL MATRIX CONSOLIDATED: " . count($leadsToDelete) . " records targeted.", 'WARNING');

// STEP 5: OUTPUT PROTOCOLS
$logData = [
    'timestamp' => date('Y-m-d H:i:s'),
    'spa_source' => [
        'id' => TARGET_SPA_ID,
        'phone' => $spaPhone,
        'email' => $spaEmail
    ],
    'kept_lead' => $latestLead,
    'flagged_for_deletion' => array_values($leadsToDelete)
];

file_put_contents(JSON_PREVIEW_FILE, json_encode($logData, JSON_PRETTY_PRINT));

if (!DRY_RUN) {
    foreach ($leadsToDelete as $id => $lead) {
        callB24('crm.lead.delete', ['id' => $id]);
        writeLog("Live Deletion Execution completed on duplicate entity ID #$id", 'SUCCESS');
    }
} else {
    writeLog("[DRY RUN ACTIVE]: Verify target data metrics safely inside file: " . JSON_PREVIEW_FILE, 'INFO');
}
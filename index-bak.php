<?php
/**
 * Bitrix24 SPA Lead Deduplication Script (With Granular Logging)
 */

// 1. CONFIGURATION
define('B24_WEBHOOK_URL', 'https://b24-sgn7y5.bitrix24.in/rest/14/kdho27qenzo9pv03/'); 
define('SPA_ENTITY_TYPE_ID', 1038); // Verified SPA Entity Type ID
define('TARGET_SPA_ID', 2);         // Verified test Item ID

// Validated internal SPA field codes
define('SPA_PHONE_FIELD', 'ufCrm8Phone'); 
define('SPA_EMAIL_FIELD', 'ufCrm8Email'); 

// SET TO 'false' ONLY AFTER YOU HAVE VERIFIED THE TEST LOGS
define('DRY_RUN', true); 

// PATHS FOR LOG FILE outputs
define('ACTIVITY_LOG_FILE', __DIR__ . '/dedup_activity.log');
define('JSON_PREVIEW_FILE', __DIR__ . '/b24_dedup_test_log.json');

// ==========================================
// CENTRALIZED LOGGING FUNCTION
// ==========================================
function writeLog($message, $level = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $formattedMessage = "[$timestamp] [$level] $message" . PHP_EOL;
    
    // Output to screen (Terminal or Browser)
    echo $formattedMessage;
    
    // Append to local log file
    file_put_contents(ACTIVITY_LOG_FILE, $formattedMessage, FILE_APPEND);
}

// ==========================================
// HELPER FUNCTION FOR API CALLS WITH LOGGING
// ==========================================
function callB24($method, $params = []) {
    $url = rtrim(B24_WEBHOOK_URL, '/') . '/' . $method . '.json';
    
    writeLog("Initiating API Call to method: '$method'", 'DEBUG');
    writeLog("API Parameters sent: " . json_encode($params), 'DEBUG');
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params),
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    // curl_close($ch); // FIX: Reactivated curl close to prevent connection hanging/leaks
    
    writeLog("API HTTP Response Code received: $httpCode", 'DEBUG');
    
    $decoded = json_decode($response, true);
    if (isset($decoded['error'])) {
        writeLog("Bitrix24 API Error returned: " . ($decoded['error_description'] ?? $decoded['error']), 'ERROR');
    }
    
    return $decoded['result'] ?? null;
}

// ==========================================
// START OF EXECUTION PIPELINE
// ==========================================
writeLog("==========================================================================");
writeLog("SCRIPT START: Initializing Lead Deduplication Engine.");
writeLog("Execution Mode: " . (DRY_RUN ? "DRY-RUN MODE (Safe - No deletions will occur)" : "LIVE MODE (Destructive - Duplicates WILL be deleted)"), DRY_RUN ? 'INFO' : 'WARNING');

// STEP 1: Fetch the Source SPA Item
writeLog("Step 1: Fetching source data from SPA Entity " . SPA_ENTITY_TYPE_ID . " for Item ID " . TARGET_SPA_ID);
$spaItem = callB24('crm.item.get', [
    'entityTypeId' => SPA_ENTITY_TYPE_ID,
    'id'           => TARGET_SPA_ID
]);

if (!$spaItem || !isset($spaItem['item'])) {
    writeLog("Critical Failure: Source SPA Item ID " . TARGET_SPA_ID . " could not be found or API returned an invalid structure.", 'CRITICAL');
    exit(1);
}
writeLog("Successfully retrieved target SPA item data.", 'SUCCESS');

// Extract communication channels
$phone = $spaItem['item'][SPA_PHONE_FIELD] ?? '';
$email = $spaItem['item'][SPA_EMAIL_FIELD] ?? '';

// Array parsing sanity checks
if (is_array($phone)) {
    writeLog("SPA Phone field structured as array. Extracting primary element value.", 'DEBUG');
    $phone = current($phone)['VALUE'] ?? current($phone);
}
if (is_array($email)) {
    writeLog("SPA Email field structured as array. Extracting primary element value.", 'DEBUG');
    $email = current($email)['VALUE'] ?? current($email);
}

// Strip whitespaces to ensure robust lookups
$phone = trim((string)$phone);
$email = trim((string)$email);

writeLog("Extraction complete. Target criteria located -> Phone: '" . ($phone ?: '[EMPTY]') . "' | Email: '" . ($email ?: '[EMPTY]') . "'");

if (empty($phone) && empty($email)) {
    writeLog("Aborting execution: Both email and phone data points are empty. Processing further would trigger accidental full CRM entity deletions.", 'CRITICAL');
    exit(1);
}

// Instantiate master registry to assemble historical data mapping
$matchedLeads = [];

// Dynamically construct search arrays
$filterOR = ['LOGIC' => 'OR'];
if (!empty($phone)) $filterOR[] = ['=PHONE' => $phone];
if (!empty($email)) $filterOR[] = ['=EMAIL' => $email];

// STEP 2: Find Old Leads Directly Matching Phone/Email
writeLog("Step 2: Querying 'crm.lead.list' for direct matching communication records.");
$directLeads = callB24('crm.lead.list', [
    'filter' => $filterOR,
    'select' => ['ID', 'TITLE', 'DATE_CREATE', 'CONTACT_ID']
]);

if (is_array($directLeads) && !empty($directLeads)) {
    writeLog("Found " . count($directLeads) . " lead record(s) directly holding matching phone or email values.", 'INFO');
    foreach ($directLeads as $lead) {
        $matchedLeads[$lead['ID']] = $lead;
        writeLog("Mapped Direct Lead Discovery -> ID: #{$lead['ID']} | Title: '{$lead['TITLE']}' | Created: {$lead['DATE_CREATE']}", 'DEBUG');
    }
} else {
    writeLog("No direct lead records matched the phone/email criteria.", 'INFO');
}

// STEP 3: Find Matching Contacts & Fetch Their Linked Leads
writeLog("Step 3: Querying 'crm.contact.list' to identify independent contact cards containing matching parameters.");
$contacts = callB24('crm.contact.list', [
    'filter' => $filterOR,
    'select' => ['ID']
]);

if (!empty($contacts) && is_array($contacts)) {
    $contactIds = array_column($contacts, 'ID');
    writeLog("Found " . count($contactIds) . " distinct Contact ID(s) matching criteria: [" . implode(', ', $contactIds) . "]", 'INFO');
    
    writeLog("Querying 'crm.lead.list' for all background leads referencing these discovered Contact IDs.");
    $contactLeads = callB24('crm.lead.list', [
        'filter' => ['=CONTACT_ID' => $contactIds],
        'select' => ['ID', 'TITLE', 'DATE_CREATE', 'CONTACT_ID']
    ]);
    
    if (is_array($contactLeads) && !empty($contactLeads)) {
        writeLog("Found " . count($contactLeads) . " lead record(s) linked via matched contact cards.", 'INFO');
        foreach ($contactLeads as $lead) {
            if (isset($matchedLeads[$lead['ID']])) {
                writeLog("Lead ID #{$lead['ID']} already fetched by direct match. Skipping duplicate registration.", 'DEBUG');
                continue;
            }
            $matchedLeads[$lead['ID']] = $lead;
            writeLog("Mapped Relational Contact Lead Discovery -> ID: #{$lead['ID']} | Title: '{$lead['TITLE']}' | Created: {$lead['DATE_CREATE']}", 'DEBUG');
        }
    } else {
        writeLog("No active leads found attached to the matched contact cards.", 'INFO');
    }
} else {
    writeLog("No contact card components matched the criteria coordinates.", 'INFO');
}

// STEP 4: Deduplication Evaluation
writeLog("Step 4: Consolidating and evaluating entire dataset registry map.");
$totalFoundCount = count($matchedLeads);
writeLog("Total unique matching leads identified across all channels: $totalFoundCount", 'INFO');

if ($totalFoundCount <= 1) {
    writeLog("Process completed cleanly: Registry holds $totalFoundCount record(s). No duplicate conflicts exist. Execution terminating.", 'SUCCESS');
    exit(0);
}

writeLog("Multiple duplicate conflicts confirmed. Executing chronological sorting sequence (ID Descending order).", 'INFO');
// Sort by ID numeric descending (Bitrix24 IDs are sequential; higher ID values indicate newer items)
uasort($matchedLeads, function($a, $b) {
    return (int)$b['ID'] - (int)$a['ID'];
});

// Protect the latest element
$latestLead = array_shift($matchedLeads); 
$leadsToDelete = $matchedLeads; 

writeLog("Deduplication logic completed successfully.", 'SUCCESS');
writeLog(">>>> PROTECTED RECORD (WINNER): ID #{$latestLead['ID']} | Title: '{$latestLead['TITLE']}' | Date: {$latestLead['DATE_CREATE']}", 'INFO');
writeLog(">>>> TARGET DISPOSAL SCOPE: " . count($leadsToDelete) . " historical record(s) isolated for deletion.", 'WARNING');

// STEP 5: Execution Protocol
writeLog("Step 5: Beginning execution protocol processing loop.");
if (DRY_RUN) {
    writeLog("Assembling dry-run data payload matrix for debugging logs...", 'DEBUG');
    $logData = [
        'timestamp' => date('Y-m-d H:i:s'),
        'spa_source' => ['id' => TARGET_SPA_ID, 'phone' => $phone, 'email' => $email],
        'kept_lead' => $latestLead,
        'flagged_for_deletion' => array_values($leadsToDelete)
    ];
    
    file_put_contents(JSON_PREVIEW_FILE, json_encode($logData, JSON_PRETTY_PRINT));
    writeLog("[DRY RUN BLOCK ACTIVATED]: Bypass call issued. Action maps written safely to backup file: " . JSON_PREVIEW_FILE, 'SUCCESS');
    writeLog("Please review the JSON payload array before disabling DRY_RUN tracking modes.", 'INFO');
} else {
    writeLog("CRITICAL WARNING: Dry run bypass confirmed. Entering live system data destruction phase.", 'WARNING');
    foreach ($leadsToDelete as $id => $lead) {
        writeLog("Attempting destructive deletion call on Lead ID #$id ('{$lead['TITLE']}')");
        $result = callB24('crm.lead.delete', ['id' => $id]);
        
        if ($result) {
            writeLog("Successfully deleted duplicate Lead ID #$id from Bitrix24 environment.", 'SUCCESS');
        } else {
            writeLog("System error encountered: Unable to purge Lead ID #$id.", 'ERROR');
        }
    }
    writeLog("Live data purge execution pipeline concluded.", 'SUCCESS');
}

writeLog("SCRIPT EXECUTION COMPLETED SUCCESSFULLY.");
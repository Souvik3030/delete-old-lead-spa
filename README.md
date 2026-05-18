Here’s what the current `index.php` does, step by step:

1. Sets config:
   - SPA entity: `1038`
   - Phone field: `ufCrm8Phone`
   - Email field: `ufCrm8Email`
   - Matching rule: `same_last_10_phone_digits`
   - Default `dry_run`: `true`

2. Reads dry-run mode:
   - Default URL runs safe mode:
     `index.php`
   - Dry run:
     `index.php?dry_run=true`
   - Live delete:
     `index.php?dry_run=false`

3. Fetches SPA items from Bitrix24 using `crm.item.list`.

4. Only fetches records in the Start stage:
   ```php
   '=stageId' => 'DT1038_14:NEW'
   ```

5. For every fetched item, it reads:
   - ID
   - Title
   - Created date
   - Phone
   - Email

6. It skips records with empty phone numbers.

7. It normalizes phone for display:
   - `+91 9999999999` becomes `+919999999999`
   - `9999999999` stays `9999999999`

8. It creates a phone match key using the last 10 digits:
   - `+919999999999` becomes `9999999999`
   - `9999999999` becomes `9999999999`

9. It loops through each SPA item as the source item.

10. For each source item, it scans all other fetched Start-stage items.

11. If another item has the same `PHONE_MATCH_KEY`, it is treated as a duplicate.

12. Email is kept in the JSON, but email is not used for matching.

13. For each duplicate group, it sorts by newest:
   - First by `DATE_CREATE`
   - If date is empty/unusable, by highest ID

14. It keeps the latest item.

15. It flags all older matching items for deletion.

16. It writes the full preview to:
   ```text
   b24_dedup_test_log.json
   ```

17. It writes activity logs to:
   ```text
   bulk_dedup_activity.log
   ```

18. If `dry_run=true`, nothing is deleted.

19. If `dry_run=false`, it deletes each flagged duplicate SPA item using:
   ```php
   crm.item.delete
   ```

20. It pauses `100ms` between delete calls to reduce API pressure.
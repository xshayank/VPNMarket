# Manual QA Testing Guide: Ticket Reply Attachment-Only Fix

## Overview
This guide covers manual testing steps for the ticket reply attachment bug fix. The bug prevented users from submitting ticket replies with only an attachment (without a message), causing a database constraint violation.

## What Was Fixed
1. **Validation**: Added `required_without` validation - either message or attachment is now required (but not both)
2. **Message Normalization**: Empty/whitespace messages are stored as empty strings when attachment is present
3. **File Types**: Updated to support jpg, jpeg, png, webp, pdf, txt, log, zip (max 5MB)
4. **UI Updates**: Help text now indicates "Write a message or attach a file"
5. **Reseller Support**: Added reply method to reseller ticket controller

## Test Scenarios

### Normal User Ticket Replies

#### Test 1: Reply with Only Attachment
**Steps:**
1. Log in as a normal user
2. Navigate to an existing ticket (or create a new one)
3. In the reply form, leave the message field empty
4. Select an attachment (try image, PDF, or log file)
5. Submit the reply

**Expected Result:**
- Reply submits successfully
- Success message: "پاسخ شما با موفقیت ثبت شد"
- Reply appears in the conversation with only the attachment download link
- No message text is shown (since it's empty)

#### Test 2: Reply with Only Message
**Steps:**
1. Log in as a normal user
2. Navigate to an existing ticket
3. Type a message in the reply field
4. Do NOT attach any file
5. Submit the reply

**Expected Result:**
- Reply submits successfully
- Success message: "پاسخ شما با موفقیت ثبت شد"
- Reply appears with the message text
- No attachment link is shown

#### Test 3: Reply with Both Message and Attachment
**Steps:**
1. Log in as a normal user
2. Navigate to an existing ticket
3. Type a message in the reply field
4. Select an attachment
5. Submit the reply

**Expected Result:**
- Reply submits successfully
- Both message and attachment are displayed in the reply

#### Test 4: Reply with Neither (Validation Error)
**Steps:**
1. Log in as a normal user
2. Navigate to an existing ticket
3. Leave message field empty
4. Do NOT attach any file
5. Submit the reply

**Expected Result:**
- Form validation error appears
- Error message indicates that either message or attachment is required
- Reply is NOT created

#### Test 5: Reply with Whitespace-Only Message (Validation Error)
**Steps:**
1. Log in as a normal user
2. Navigate to an existing ticket
3. Enter only spaces/tabs in the message field
4. Do NOT attach any file
5. Submit the reply

**Expected Result:**
- Form validation error appears (trimmed message is empty)
- Reply is NOT created

#### Test 6: Test Various File Types
**Steps:**
1. Try uploading each of these file types individually:
   - JPG image
   - JPEG image
   - PNG image
   - WEBP image
   - PDF document
   - TXT file
   - LOG file
   - ZIP archive

**Expected Result:**
- All file types should be accepted
- Files should be downloadable after upload

#### Test 7: Test Invalid File Type
**Steps:**
1. Try uploading an .exe or .sh file

**Expected Result:**
- Validation error appears
- File is rejected

### Reseller Ticket Replies

#### Test 8: Reseller Reply with Only Attachment
**Steps:**
1. Log in as a reseller user
2. Navigate to Reseller → Tickets
3. Open an existing reseller ticket (or create one)
4. In the reply form, leave the message field empty
5. Select an attachment
6. Submit the reply

**Expected Result:**
- Reply submits successfully
- Success message: "پاسخ شما با موفقیت ثبت شد"
- Reply appears with only the attachment
- User badge shows "ریسلر"

#### Test 9: Reseller Reply with Only Message
**Steps:**
1. Log in as a reseller user
2. Navigate to a reseller ticket
3. Type a message (no attachment)
4. Submit the reply

**Expected Result:**
- Reply submits successfully
- Message is displayed

#### Test 10: Reseller Reply with Both
**Steps:**
1. Log in as a reseller user
2. Navigate to a reseller ticket
3. Type a message AND attach a file
4. Submit the reply

**Expected Result:**
- Both message and attachment are shown

#### Test 11: Reseller Validation Error
**Steps:**
1. Log in as a reseller user
2. Navigate to a reseller ticket
3. Submit reply with neither message nor attachment

**Expected Result:**
- Validation error appears
- Reply is NOT created

### Edge Cases

#### Test 12: Large File Size
**Steps:**
1. Try uploading a file larger than 5MB

**Expected Result:**
- Validation error appears
- File is rejected

#### Test 13: Ticket Status Update
**Steps:**
1. Create/find a ticket with status "answered" or "closed"
2. Submit a reply (with attachment or message)

**Expected Result:**
- Reply is created
- Ticket status should update to "open"

#### Test 14: Multiple Consecutive Replies
**Steps:**
1. Submit 3 replies in a row:
   - First with only attachment
   - Second with only message
   - Third with both

**Expected Result:**
- All 3 replies appear in conversation
- Each displays appropriately based on content

## Verification Checklist

- [ ] Normal users can submit attachment-only replies
- [ ] Normal users can submit message-only replies
- [ ] Normal users can submit replies with both
- [ ] Normal users cannot submit empty replies
- [ ] Resellers can submit attachment-only replies
- [ ] Resellers can submit message-only replies
- [ ] Resellers can submit replies with both
- [ ] Resellers cannot submit empty replies
- [ ] All supported file types are accepted (jpg, jpeg, png, webp, pdf, txt, log, zip)
- [ ] Invalid file types are rejected
- [ ] Files larger than 5MB are rejected
- [ ] Help text is visible and clear
- [ ] Empty message replies display correctly (no blank message box)
- [ ] Ticket status updates correctly after reply
- [ ] Download links work for attachments
- [ ] No database errors occur

## Database Verification (Optional)

If you have database access, you can verify:

```sql
-- Check for empty string messages (not NULL)
SELECT id, ticket_id, message, attachment_path 
FROM ticket_replies 
WHERE message = '';

-- This should NOT return any rows:
SELECT id, ticket_id, message, attachment_path 
FROM ticket_replies 
WHERE message IS NULL;
```

## Notes for Developers

- The fix ensures that the `message` column in `ticket_replies` table is never NULL
- When a reply has only an attachment, the message is stored as an empty string `''`
- The validation rule `required_without:attachment` ensures at least one of message or attachment is present
- Message trimming happens in the controller before saving
- Views conditionally display message only if it's not empty

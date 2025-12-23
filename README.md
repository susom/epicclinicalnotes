# Epic Clinical Notes (REDCap External Module)

Epic Clinical Notes is a REDCap External Module (EM) designed to push REDCap survey responses into **Epic Smart Data Elements (SDEs)** associated with a patient encounter. The module allows flexible mapping between REDCap fields and Epic SDE fields and supports batching, formatting, and secure authentication via Epic OAuth.

This README explains what the module does, how it works, and how to configure it correctly.

---

## Overview

This External Module enables automated synchronization of REDCap survey data into Epic by:

- Mapping one or more REDCap fields to a single Epic Clinical Notes Smart Data Element (SDE)
- Formatting survey responses in a clinician‑friendly, readable format
- Identifying the correct patient record using MRN
- Securely authenticating with Epic using the **Epic Authenticator** External Module
- Periodically pushing new or updated responses using a scheduled cron job

The primary use case is to surface structured research or survey data directly in Epic clinical workflows.

---

## Dependencies

### Required External Module

This module **depends on the Epic Authenticator External Module**.

- You must install, enable, and configure the Epic Authenticator EM on the **same REDCap project**
- This module uses the Epic Authenticator EM to obtain OAuth access tokens required to push data to Epic
- The system setting **Epic Authenticator EM Prefix** must match the installed Epic Authenticator module prefix

Without the Epic Authenticator EM properly configured, this module will not function.

---

## How Data Is Sent to Epic

- Each mapping connects an Epic SDE field to one or more REDCap fields
- Multiple REDCap fields can be mapped to the **same Epic SDE**
- Field values are formatted into a single text payload and pushed to Epic Clinical Notes

### Formatting Logic

For each mapped REDCap field:

- The REDCap **field label** is evaluated (including any REDCap Smart Variables)
- The field response value is appended after the label
- Multiple fields are separated using a configurable divider

**Example output sent to Epic SDE:**

```
Field Label 1: Response 1 | Field Label 2: Response 2
```

The divider character (`|` by default) can be customized to improve readability for clinicians.

---

## Configuration

Configuration is split between **System Settings** and **Project Settings**.

### System Settings

These settings apply at the REDCap system level:

#### Epic Authenticator EM Prefix
- Enter the prefix (directory name) of the Epic Authenticator EM
- Example: `epic_authenticator`
- Required for token retrieval and Epic API access

#### Epic Access Token / Timestamp
- Managed automatically by the system
- Tokens are valid for one hour
- No manual input required

---

### Project Settings

These settings must be configured for each REDCap project using this module.

#### REDCap MRN Field (Required)
- Select the REDCap field that contains the patient **MRN**
- This value is used to identify the patient record in Epic
- The MRN must match Epic’s expected identifier format

#### Divider Between Epic Responses (Optional)
- Defines the separator used between multiple field responses
- Default value: `|`
- Example output:
  ```
  Question A: Yes | Question B: No
  ```
- Useful for helping clinicians quickly differentiate responses in Epic

#### REDCap to Epic SDE Mapping (Required)

This is a repeatable configuration that defines how data is pushed to Epic.

For each mapping:

- **Epic SDE Field**
  - The Epic Clinical Notes Smart Data Element ID or name

- **REDCap Fields**
  - One or more REDCap fields whose responses will be combined
  - Each field contributes:
    ```
    Field Label: Response
    ```
  - Field labels support REDCap Smart Variables

Multiple mappings can be defined, and each mapping can target a different Epic SDE.

---

## Cron Job Processing

This module includes a scheduled cron job:

- **Cron name:** `sync-epic-clinical-notes-batch-process`
- Runs every 60 minutes by default
- Batches and pushes eligible REDCap survey responses to Epic

The cron job:
- Retrieves an access token via the Epic Authenticator EM
- Builds SDE payloads based on configured mappings
- Sends formatted clinical notes to Epic **only when the target SDE value is empty / unset**
- **Does not override existing SDE values** in Epic (it only sets new values)
- Logs success and error responses for troubleshooting

This cron job only sets new values and does not override existing Epic SDE values.

---

## Security Considerations

- OAuth access tokens are retrieved dynamically and stored temporarily
- No Epic credentials or private keys are stored directly in this module
- Authentication and key management are delegated to the Epic Authenticator EM
- Ensure REDCap project permissions restrict access to MRN fields appropriately

---

## Troubleshooting

**No Data Appearing in Epic**
- Verify the Epic Authenticator EM is enabled and configured correctly
- Confirm the correct EM prefix is entered in system settings
- Ensure the MRN field contains valid values

**Formatting Issues**
- Check the configured divider value
- Verify field labels are correct and Smart Variables resolve as expected

**Authentication Errors**
- Review REDCap logs for Epic API or OAuth errors
- Confirm the Epic Authenticator EM can successfully obtain access tokens

---

This module is intended to be used in conjunction with Epic Clinical workflows and should be tested in non‑production environments before deployment.

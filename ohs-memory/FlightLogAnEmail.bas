Attribute VB_Name = "FlightLogAnEmail"
Option Explicit
' ============================================================================
' FlightLog — Add Selected Email to the Archive
' ============================================================================
'
' PURPOSE
'   When you find an email that should be in FlightLog but isn't, select it
'   in Outlook and run this macro.  It sends the email to the FlightLog
'   server, which chunks it, embeds it, and stores it in the database.
'   Then it opens FlightLog in your browser with the email subject pre-filled
'   as a search — so you can immediately verify the email is in the archive.
'
' INSTALLATION (one-time)
'   1. In Outlook, press Alt+F11 to open the VBA editor
'   2. In the Project pane (left side), expand "Project1" or your project name
'   3. Right-click "Modules" → Insert → Module
'   4. Paste this entire file into the blank module
'   5. Press F5 or click Run > Run Sub/UserForm to run it
'
'   Optional — assign to a toolbar button or keyboard shortcut:
'   File > Options > Customize Ribbon > Keyboard Shortcuts > Macros
'
' USAGE
'   1. Click on any email in Outlook to select it
'   2. Run the macro (Alt+F8 → IngestSelectedEmail → Run)
'   3. Confirm the dialog
'   4. Wait ~10-30 seconds while the server processes the email
'   5. Your browser opens FlightLog with the email subject searched —
'      if the email shows up in results, it's in the archive
'
' WHAT COUNTS AS "ALREADY IN FLIGHTLOG"
'   The server hashes the email content.  If the exact same email text was
'   previously ingested (by anyone), it will say "Already in FlightLog" —
'   that's a success, not an error.
'
' LIMITATIONS
'   - Plain-text body only (attachments are not included)
'   - Body is capped at 50 000 characters (enough for any normal email thread)
'   - Requires an internet connection to reach psd1.net
' ============================================================================

' ── Configuration (set by your IT contact — do not share the INGEST_KEY) ─────

Private Const ENDPOINT_URL As String = "https://psd1.net/ohs-search/ingest-email-proxy.php"
Private Const FLIGHTLOG_URL As String = "https://psd1.net/ohs-search"
Private Const INGEST_KEY    As String = "369d865a081516765fd934611a2afda2813774aeaee36f3cca1e8ccfe1156f4f"

Private Const MAX_BODY_CHARS As Long = 50000

' ─────────────────────────────────────────────────────────────────────────────

Sub IngestSelectedEmail()

    ' ── 1. Validate selection ──────────────────────────────────────────────────
    Dim oSel As Outlook.Selection
    Set oSel = Application.ActiveExplorer.Selection

    If oSel.Count = 0 Then
        MsgBox "No email selected." & vbCrLf & vbCrLf & _
               "Click on an email in the folder list first, then run this macro.", _
               vbExclamation, "FlightLog"
        Exit Sub
    End If

    If oSel.Count > 1 Then
        If MsgBox("You have " & oSel.Count & " items selected." & vbCrLf & _
                  "This macro adds ONE email at a time." & vbCrLf & vbCrLf & _
                  "Continue with the first selected email?", _
                  vbYesNo + vbQuestion, "FlightLog") = vbNo Then
            Exit Sub
        End If
    End If

    If oSel(1).Class <> olMail Then
        MsgBox "The selected item is not an email." & vbCrLf & _
               "Select a mail message and try again.", _
               vbExclamation, "FlightLog"
        Exit Sub
    End If

    Dim oMail As Outlook.MailItem
    Set oMail = oSel(1)

    ' ── 2. Extract email fields ────────────────────────────────────────────────
    Dim subject     As String
    Dim sender      As String
    Dim senderName  As String
    Dim toField     As String
    Dim dateStr     As String
    Dim emailBody   As String

    subject    = Trim(oMail.subject)
    If subject = "" Then subject = "(no subject)"

    sender     = Trim(oMail.SenderEmailAddress)
    senderName = Trim(oMail.SenderName)
    toField    = Trim(oMail.To)

    On Error Resume Next
    Dim sentDate As Date
    sentDate = oMail.SentOn
    If Err.Number = 0 And sentDate <> 0 And sentDate <> CDate("1/1/4501") Then
        dateStr = Format(sentDate, "YYYY-MM-DD HH:MM:SS")
    Else
        dateStr = Format(Now, "YYYY-MM-DD HH:MM:SS")
    End If
    On Error GoTo 0

    ' Prefer plain-text body; fall back to stripping HTML tags
    emailBody = Trim(oMail.Body)
    If Len(emailBody) < 20 And Len(oMail.HTMLBody) > 0 Then
        emailBody = StripHtmlTags(oMail.HTMLBody)
    End If
    If emailBody = "" Then
        MsgBox "This email appears to have no readable text body." & vbCrLf & _
               "It may be image-only or have an unusual format.", _
               vbExclamation, "FlightLog"
        Exit Sub
    End If

    ' Cap body length to avoid overloading the server
    Dim bodyTruncated As Boolean
    bodyTruncated = False
    If Len(emailBody) > MAX_BODY_CHARS Then
        emailBody     = Left(emailBody, MAX_BODY_CHARS)
        bodyTruncated = True
    End If

    ' ── 3. Confirm with user ───────────────────────────────────────────────────
    Dim preview As String
    preview = "Subject:  " & subject & vbCrLf & _
              "From:     " & senderName & vbCrLf & _
              "Date:     " & dateStr & vbCrLf & _
              "Body:     " & Len(emailBody) & " characters"
    If bodyTruncated Then preview = preview & " (truncated)"

    If MsgBox("Add this email to FlightLog?" & vbCrLf & vbCrLf & _
              preview & vbCrLf & vbCrLf & _
              "FlightLog will open when done so you can verify the result.", _
              vbYesNo + vbQuestion, "FlightLog") = vbNo Then
        Exit Sub
    End If

    ' ── 4. Build JSON request body ─────────────────────────────────────────────
    Dim jsonBody As String
    jsonBody = "{" & _
        """key"":"        & JsonStr(INGEST_KEY)   & "," & _
        """subject"":"    & JsonStr(subject)       & "," & _
        """sender"":"     & JsonStr(sender)        & "," & _
        """sender_name"":" & JsonStr(senderName)   & "," & _
        """to"":"         & JsonStr(toField)       & "," & _
        """date"":"       & JsonStr(dateStr)       & "," & _
        """body"":"       & JsonStr(emailBody)     & _
    "}"

    ' ── 5. POST to FlightLog ingest endpoint ──────────────────────────────────
    Dim http As Object
    Set http = CreateObject("WinHttp.WinHttpRequest.5.1")

    On Error GoTo NetworkError
    http.Open "POST", ENDPOINT_URL, False  ' synchronous
    http.SetRequestHeader "Content-Type", "application/json"
    http.SetTimeouts 10000, 10000, 30000, 60000  ' 60s receive timeout
    http.Send jsonBody
    On Error GoTo 0

    ' ── 6. Parse response ──────────────────────────────────────────────────────
    Dim responseText As String
    responseText = http.ResponseText

    If http.Status <> 200 Then
        MsgBox "Server returned an error (HTTP " & http.Status & "):" & vbCrLf & vbCrLf & _
               Left(responseText, 500), vbExclamation, "FlightLog"
        Exit Sub
    End If

    ' Simple JSON field extraction (no library needed for this predictable response)
    Dim ok       As Boolean
    Dim skipped  As Boolean
    Dim docId    As String
    Dim chunkCt  As String
    Dim yr       As String
    Dim teacher  As String
    Dim errMsg   As String

    ok      = (InStr(responseText, """ok"":true")    > 0)
    skipped = (InStr(responseText, """skipped"":true") > 0)
    docId   = JsonGetString(responseText, "doc_id")
    chunkCt = JsonGetString(responseText, "chunks")
    yr      = JsonGetString(responseText, "year")
    teacher = JsonGetString(responseText, "teacher")
    errMsg  = JsonGetString(responseText, "error")

    ' ── 7. Open FlightLog for verification ────────────────────────────────────
    Dim verifyURL As String
    verifyURL = FLIGHTLOG_URL & "?q=" & URLEncode(subject)

    Dim wsh As Object
    Set wsh = CreateObject("WScript.Shell")
    wsh.Run "explorer """ & verifyURL & """"

    ' ── 8. Show result to user ─────────────────────────────────────────────────
    If errMsg <> "" Then
        MsgBox "FlightLog returned an error:" & vbCrLf & vbCrLf & errMsg, _
               vbExclamation, "FlightLog"

    ElseIf skipped Then
        MsgBox "This email is already in FlightLog." & vbCrLf & vbCrLf & _
               "Document ID: " & docId & vbCrLf & vbCrLf & _
               "FlightLog has opened in your browser to confirm.", _
               vbInformation, "FlightLog — Already Archived"

    ElseIf ok Then
        Dim resultMsg As String
        resultMsg = "Email added to FlightLog!" & vbCrLf & vbCrLf & _
                    "Subject:     " & subject & vbCrLf & _
                    "Year:        " & yr & vbCrLf & _
                    "Teacher:     " & teacher & vbCrLf & _
                    "Chunks:      " & chunkCt & vbCrLf & _
                    "Document ID: " & docId & vbCrLf & vbCrLf & _
                    "FlightLog has opened in your browser." & vbCrLf & _
                    "Verify the email appears in the search results."
        MsgBox resultMsg, vbInformation, "FlightLog — Archived"
    Else
        MsgBox "Unexpected server response:" & vbCrLf & vbCrLf & _
               Left(responseText, 500), vbExclamation, "FlightLog"
    End If

    Exit Sub

NetworkError:
    MsgBox "Could not reach the FlightLog server." & vbCrLf & vbCrLf & _
           "Check your internet connection and try again." & vbCrLf & _
           "Error: " & Err.Description, vbExclamation, "FlightLog"
End Sub


' ── JSON helpers ──────────────────────────────────────────────────────────────

' Wrap a string value as a JSON string literal, escaping special characters.
Private Function JsonStr(s As String) As String
    Dim result As String
    Dim i      As Integer
    Dim c      As String
    Dim code   As Integer

    result = """"
    For i = 1 To Len(s)
        c    = Mid(s, i, 1)
        code = AscW(c)
        Select Case code
            Case 34:  result = result & "\"""   ' "
            Case 92:  result = result & "\\"    ' \
            Case 10:  result = result & "\n"    ' newline
            Case 13:  result = result & "\r"    ' carriage return
            Case 9:   result = result & "\t"    ' tab
            Case Is < 32                        ' other control chars
                result = result & "\u" & Right("000" & Hex(code), 4)
            Case Else
                result = result & c
        End Select
    Next i
    result = result & """"
    JsonStr = result
End Function

' Extract the string value of a JSON field by name from a flat JSON object.
' Works for both string values ("field":"value") and number values ("field":123).
' Returns "" if the field is not found.
Private Function JsonGetString(json As String, fieldName As String) As String
    Dim pattern As String
    Dim pos     As Long
    Dim valStart As Long
    Dim valEnd  As Long
    Dim rawVal  As String

    ' Look for "fieldName":" (string value) or "fieldName":N (number value)
    pattern = """" & fieldName & """:"

    pos = InStr(json, pattern)
    If pos = 0 Then
        JsonGetString = ""
        Exit Function
    End If

    valStart = pos + Len(pattern)

    If Mid(json, valStart, 1) = """" Then
        ' String value — find closing quote (not escaped)
        valStart = valStart + 1
        valEnd = valStart
        Do While valEnd <= Len(json)
            If Mid(json, valEnd, 1) = "\" Then
                valEnd = valEnd + 2  ' skip escaped character
            ElseIf Mid(json, valEnd, 1) = """" Then
                Exit Do
            Else
                valEnd = valEnd + 1
            End If
        Loop
        rawVal = Mid(json, valStart, valEnd - valStart)
        ' Unescape common sequences
        rawVal = Replace(rawVal, "\n", vbLf)
        rawVal = Replace(rawVal, "\r", vbCr)
        rawVal = Replace(rawVal, "\t", vbTab)
        rawVal = Replace(rawVal, "\\", "\")
        rawVal = Replace(rawVal, "\""", """")
        JsonGetString = rawVal
    Else
        ' Number or boolean — read until delimiter
        valEnd = valStart
        Do While valEnd <= Len(json)
            Dim ch As String
            ch = Mid(json, valEnd, 1)
            If ch = "," Or ch = "}" Or ch = "]" Or ch = " " Then Exit Do
            valEnd = valEnd + 1
        Loop
        JsonGetString = Mid(json, valStart, valEnd - valStart)
    End If
End Function

' Percent-encode a string for use in a URL query parameter.
' Spaces → + (standard form encoding).
Private Function URLEncode(s As String) As String
    Dim result As String
    Dim i      As Integer
    Dim c      As String
    Dim code   As Integer

    result = ""
    For i = 1 To Len(s)
        c    = Mid(s, i, 1)
        code = Asc(c)
        Select Case code
            Case 48 To 57, 65 To 90, 97 To 122  ' 0-9, A-Z, a-z
                result = result & c
            Case 45, 46, 95, 126                 ' - . _ ~
                result = result & c
            Case 32                              ' space → +
                result = result & "+"
            Case Else
                result = result & "%" & Right("0" & Hex(code), 2)
        End Select
    Next i
    URLEncode = result
End Function

' Strip HTML tags from a string (rough, good enough for email bodies).
Private Function StripHtmlTags(html As String) As String
    Dim result As String
    result = html
    ' Replace block-level tags with newlines for readability
    Dim blockTags As Variant
    blockTags = Array("<br>", "<br/>", "<br />", "<p>", "</p>", "<div>", "</div>", _
                      "<tr>", "</tr>", "<li>", "</li>")
    Dim tag As Variant
    For Each tag In blockTags
        result = Replace(result, tag, vbLf, , , vbTextCompare)
    Next tag
    ' Remove remaining tags
    Dim inTag As Boolean
    Dim out   As String
    Dim i     As Integer
    inTag = False
    out   = ""
    For i = 1 To Len(result)
        Dim ch As String
        ch = Mid(result, i, 1)
        If ch = "<" Then
            inTag = True
        ElseIf ch = ">" Then
            inTag = False
        ElseIf Not inTag Then
            out = out & ch
        End If
    Next i
    ' Collapse runs of whitespace
    Do While InStr(out, "  ") > 0
        out = Replace(out, "  ", " ")
    Loop
    StripHtmlTags = Trim(out)
End Function

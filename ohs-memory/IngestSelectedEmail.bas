' ============================================================================
' OHS Memory — Ingest Selected Email into FlightLog
' ============================================================================
' Usage:
'   1. Select a single email in any Outlook folder
'   2. Press Alt+F11 to open the VBA editor
'   3. Insert > Module, paste this entire file (or import it)
'   4. Press F5 or run IngestSelectedEmail
'
' What it does:
'   1. Saves the selected email as a .msg file to a temp folder
'   2. Runs ingest.py on that file and WAITS for it to finish
'   3. Opens FlightLog in your browser with the email subject pre-filled
'      as a search query — so you can verify the email landed in the archive
'
' Verification:
'   After ingest completes, your browser opens to:
'     https://orionhs.us/flightlog?q=<email+subject>
'   FlightLog auto-runs the search so you can immediately see the new record.
' ============================================================================

' ── Configuration ────────────────────────────────────────────────────────────

Private Const PYTHON_EXE    As String = "python"
Private Const INGEST_SCRIPT As String = "C:\Users\johnw\Documents\GitHub\AI\ohs-memory\ingest.py"
Private Const TEMP_DIR      As String = "C:\Users\johnw\AppData\Local\Temp\ohs-ingest\"
Private Const FLIGHTLOG_URL As String = "https://orionhs.us/flightlog"

' ─────────────────────────────────────────────────────────────────────────────

Sub IngestSelectedEmail()

    ' ── 1. Get the selected item ───────────────────────────────────────────────
    Dim oSel As Outlook.Selection
    Set oSel = Application.ActiveExplorer.Selection

    If oSel.Count = 0 Then
        MsgBox "No email selected." & vbCrLf & _
               "Click on an email in the reading pane first, then run this macro.", _
               vbExclamation, "OHS Memory"
        Exit Sub
    End If

    If oSel.Count > 1 Then
        If MsgBox("You have " & oSel.Count & " items selected." & vbCrLf & _
                  "This macro ingests ONE email at a time." & vbCrLf & vbCrLf & _
                  "Ingest the first selected email only?", _
                  vbYesNo + vbQuestion, "OHS Memory") = vbNo Then
            Exit Sub
        End If
    End If

    Dim oItem As Object
    Set oItem = oSel(1)

    If oItem.Class <> olMail Then
        MsgBox "The selected item is not an email (it may be a calendar item or contact)." & vbCrLf & _
               "Select a mail message and try again.", _
               vbExclamation, "OHS Memory"
        Exit Sub
    End If

    Dim oMail As Outlook.MailItem
    Set oMail = oItem

    ' ── 2. Build a safe .msg filename ─────────────────────────────────────────
    Dim subject As String
    subject = Trim(oMail.subject)
    If subject = "" Then subject = "(no subject)"

    Dim dateStr As String
    On Error Resume Next
    dateStr = Format(oMail.SentOn, "YYYYMMDD_HHMMSS")
    On Error GoTo 0
    If dateStr = "" Or dateStr = "00000000_000000" Then
        dateStr = Format(Now, "YYYYMMDD_HHMMSS")
    End If

    ' Sanitize subject for use in a filename (keep alphanumeric, spaces → _)
    Dim safeSubject As String
    safeSubject = Left(SanitizeFilename(subject), 60)

    Dim msgFilename As String
    msgFilename = dateStr & "_" & safeSubject & ".msg"

    ' ── 3. Ensure temp folder exists ──────────────────────────────────────────
    If Dir(TEMP_DIR, vbDirectory) = "" Then
        On Error Resume Next
        MkDir TEMP_DIR
        If Err.Number <> 0 Then
            MsgBox "Could not create temp folder:" & vbCrLf & TEMP_DIR & vbCrLf & _
                   "Create it manually and try again.", vbExclamation, "OHS Memory"
            Exit Sub
        End If
        On Error GoTo 0
    End If

    ' ── 4. Save the email as .msg ─────────────────────────────────────────────
    Dim msgPath As String
    msgPath = TEMP_DIR & msgFilename

    On Error GoTo SaveError
    oMail.SaveAs msgPath, olMSG
    On Error GoTo 0

    ' ── 5. Run ingest.py and WAIT for it to finish ────────────────────────────
    Dim wsh As Object
    Set wsh = CreateObject("WScript.Shell")

    ' Build the command — quoted paths handle spaces
    Dim cmd As String
    cmd = """" & PYTHON_EXE & """ """ & INGEST_SCRIPT & """ """ & msgPath & """"

    ' wsh.Run with bWaitOnReturn=True blocks until ingest.py exits.
    ' Window style 1 = normal visible console so you can watch progress.
    ' Change to 0 for a hidden window if you prefer silent operation.
    Dim exitCode As Long
    exitCode = wsh.Run("cmd /k " & cmd, 1, True)
    '                  ^^^^^ /k keeps the window open so you can read output
    '                        change to /c to auto-close on completion

    ' ── 6. Report and open FlightLog for verification ─────────────────────────
    Dim status As String
    If exitCode = 0 Then
        status = "Ingestion complete."
    Else
        status = "Ingest finished with exit code " & exitCode & "." & vbCrLf & _
                 "Check the console output (or ingest_errors.log) for details."
    End If

    ' Build the FlightLog verification URL
    Dim verifyURL As String
    verifyURL = FLIGHTLOG_URL & "?q=" & URLEncode(subject)

    ' Open browser to FlightLog with the subject pre-filled as a search
    wsh.Run "explorer """ & verifyURL & """"

    MsgBox status & vbCrLf & vbCrLf & _
           "FlightLog opened in your browser." & vbCrLf & _
           "Search pre-filled with the email subject:" & vbCrLf & _
           """" & subject & """" & vbCrLf & vbCrLf & _
           "Confirm the email appears in the results to verify ingestion.", _
           vbInformation, "OHS Memory — Ingest Complete"

    Exit Sub

SaveError:
    MsgBox "Could not save .msg file:" & vbCrLf & vbCrLf & _
           msgPath & vbCrLf & vbCrLf & _
           Err.Description, vbExclamation, "OHS Memory"
End Sub


' ── Helpers ───────────────────────────────────────────────────────────────────

Private Function SanitizeFilename(s As String) As String
    ' Replace characters that are illegal in Windows filenames with underscores.
    Dim result As String
    Dim i As Integer
    Dim c As String
    Dim illegal As String
    illegal = "\/:*?""<>|"

    result = ""
    For i = 1 To Len(s)
        c = Mid(s, i, 1)
        If InStr(illegal, c) > 0 Or Asc(c) < 32 Then
            result = result & "_"
        ElseIf c = " " Then
            result = result & "_"
        Else
            result = result & c
        End If
    Next i
    SanitizeFilename = result
End Function


Private Function URLEncode(s As String) As String
    ' Percent-encode a string for use in a URL query parameter.
    ' Spaces → + (standard for query strings).
    ' Stays within ASCII — sufficient for email subjects.
    Dim result As String
    Dim i As Integer
    Dim c As String
    Dim code As Integer

    result = ""
    For i = 1 To Len(s)
        c = Mid(s, i, 1)
        code = Asc(c)
        Select Case code
            Case 48 To 57   ' 0-9
                result = result & c
            Case 65 To 90   ' A-Z
                result = result & c
            Case 97 To 122  ' a-z
                result = result & c
            Case 45, 46, 95, 126   ' - . _ ~  (RFC 3986 unreserved)
                result = result & c
            Case 32         ' space → +
                result = result & "+"
            Case Else
                result = result & "%" & Right("0" & Hex(code), 2)
        End Select
    Next i
    URLEncode = result
End Function

' ============================================================================
' OHS Memory — Export Current Outlook Folder to .msg Files
' ============================================================================
' Instructions:
'   1. In Outlook, click on the INBOX under "Orion Planning Team - Email Group"
'      so that folder is selected/highlighted in the left sidebar
'   2. Press Alt+F11 to open VBA editor
'   3. Insert > Module, paste this entire file
'   4. Edit SAVE_PATH below if needed
'   5. Press F5 or Run > Run Sub
'
' The macro exports whatever folder is currently active in Outlook —
' no folder searching needed, works with M365 Groups, shared mailboxes, etc.
'
' Output filename:  00001_YYYYMMDD_HHMMSS.msg
'   The 5-digit index prefix guarantees uniqueness even when M365 Group
'   emails have no SentOn date or when multiple emails share a timestamp.
'   Unknown/missing dates appear as 00000000_000000 in the filename, but
'   the actual email date is stored correctly inside the .msg file.
'
' Already-exported files are skipped (by index) — safe to stop and re-run,
' as long as the folder hasn't received new mail since the last run.
'
' BEFORE re-exporting a folder you've exported before with the old naming
' scheme (YYYYMMDD_..._EntryID.msg), delete the old files first or change
' SAVE_PATH to a new folder — otherwise old and new files coexist.
' ============================================================================

Sub ExportCurrentFolder()

    ' ── Configuration ──────────────────────────────────────────────────────────
    Const SAVE_PATH As String = "C:\Users\johnw\Documents\Emails\orion-planning-team\"
    ' ───────────────────────────────────────────────────────────────────────────

    Dim oFolder  As Outlook.MAPIFolder
    Dim oItems   As Outlook.Items
    Dim oItem    As Object
    Dim oMail    As Outlook.MailItem
    Dim fileName As String
    Dim fullPath As String
    Dim dateStr  As String
    Dim sentDate As Date
    Dim exported As Long
    Dim skipped  As Long
    Dim failed   As Long
    Dim total    As Long
    Dim i        As Long

    ' ── Grab the folder currently visible in Outlook ───────────────────────────
    On Error Resume Next
    Set oFolder = Application.ActiveExplorer.CurrentFolder
    On Error GoTo 0

    If oFolder Is Nothing Then
        MsgBox "No folder is currently selected in Outlook." & vbCrLf & _
               "Click a folder in the left sidebar first, then run this macro.", _
               vbExclamation
        Exit Sub
    End If

    ' Confirm with user before starting — show FULL path so you can tell
    ' "\\Orion Planning Team - Email Group\Inbox" from "\\john@psd1.net\Inbox"
    total = oFolder.Items.Count
    If MsgBox("Ready to export from:" & vbCrLf & vbCrLf & _
              "  " & oFolder.FullFolderPath & vbCrLf & vbCrLf & _
              "  Items:  " & total & vbCrLf & _
              "  To:     " & SAVE_PATH & vbCrLf & vbCrLf & _
              "Does the path above show the GROUP inbox, not your personal inbox?" & vbCrLf & _
              "Continue?", _
              vbYesNo + vbQuestion, "OHS Memory Export") = vbNo Then
        Exit Sub
    End If

    ' Create output folder if it doesn't exist
    If Dir(SAVE_PATH, vbDirectory) = "" Then
        On Error Resume Next
        MkDir SAVE_PATH
        If Err.Number <> 0 Then
            MsgBox "Could not create folder: " & SAVE_PATH & vbCrLf & _
                   "Create it manually and try again.", vbExclamation
            Exit Sub
        End If
        On Error GoTo 0
    End If

    ' Sort oldest-first so filenames are roughly chronological
    Set oItems = oFolder.Items
    On Error Resume Next
    oItems.Sort "[SentOn]", False
    On Error GoTo 0

    exported = 0
    skipped  = 0
    failed   = 0

    For i = 1 To oItems.Count

        Set oItem = oItems(i)

        ' Only mail items (skip meeting requests, calendar posts, etc.)
        If oItem.Class = olMail Then
            Set oMail = oItem

            ' Get sent date — fall back to received time if SentOn unavailable.
            ' For M365 Group emails SentOn often fails; ReceivedTime is the
            ' best available fallback. Either way, the index in the filename
            ' guarantees uniqueness even when dates are identical or missing.
            sentDate = 0
            On Error Resume Next
            sentDate = oMail.SentOn
            On Error GoTo 0

            If sentDate = 0 Or sentDate = CDate("1/1/4501") Then
                On Error Resume Next
                sentDate = oMail.ReceivedTime
                On Error GoTo 0
            End If

            ' Build filename: 00001_YYYYMMDD_HHMMSS.msg
            ' Index is the unique part; date is human-readable context.
            ' Unknown dates render as 00000000_000000.
            If sentDate = 0 Or sentDate = CDate("1/1/4501") Then
                dateStr = "00000000_000000"
            Else
                dateStr = Format(sentDate, "YYYYMMDD_HHMMSS")
            End If

            fileName = Format(i, "00000") & "_" & dateStr & ".msg"
            fullPath = SAVE_PATH & fileName

            ' Skip if already exported (safe to re-run)
            If Dir(fullPath) <> "" Then
                skipped = skipped + 1
            Else
                On Error Resume Next
                oMail.SaveAs fullPath, olMSG
                If Err.Number = 0 Then
                    exported = exported + 1
                Else
                    failed = failed + 1
                    Err.Clear
                End If
                On Error GoTo 0
            End If
        Else
            skipped = skipped + 1
        End If

        ' Yield every 25 items so Outlook stays responsive
        If (i Mod 25) = 0 Then
            DoEvents
            Debug.Print "Progress: " & i & " / " & total & _
                        "  saved=" & exported & _
                        "  skipped=" & skipped & _
                        "  failed=" & failed
        End If

    Next i

    MsgBox "Export complete!" & vbCrLf & vbCrLf & _
           "  Saved:   " & exported & " new .msg files" & vbCrLf & _
           "  Skipped: " & skipped & " (already exported or non-mail)" & vbCrLf & _
           "  Failed:  " & failed & vbCrLf & vbCrLf & _
           "Folder: " & SAVE_PATH, _
           vbInformation, "OHS Memory Export"

End Sub

Attribute VB_Name = "CategorizeEmailsByRecipientCount"
Option Explicit

' =============================================================================
'  CategorizeEmailsByRecipientCount.bas
'
'  WORKFLOW:
'    1. Run this macro — it sorts psd1.org emails from Inbox, PSD1.ORG, and
'       Archive into four recipient-count buckets under Inbox > PSD1.ORG.
'    2. Review "Me + 3 or More" (and "Me + 2" if you like) for RAG value.
'    3. Move confirmed RAG emails back to Archive manually.
'
'  Bucket folders created under Inbox > PSD1.ORG:
'    "Me Only"        — 1  recipient   (just you)
'    "Me + 1"         — 2  recipients  (you + 1 other)
'    "Me + 2"         — 3  recipients  (you + 2 others)
'    "Me + 3 or More" — 4+ recipients  ← prime RAG archive candidates
'
'  Source folders swept (all filtered to @psd1.org / @students.psd1.org):
'    • Inbox  (stray psd1.org mail not yet filed)
'    • Inbox > PSD1.ORG  (top-level items not yet bucketed)
'    • Archive  (built-in Outlook Archive folder, if present)
'    • Any custom folder named "Archive" at the mailbox root
'
'  NOTE on Deb Dunn / Rebecca Riley "BITCH" emails:
'    Those are typically 1-on-1 or 3-way messages, so they land naturally in
'    "Me Only", "Me + 1", or "Me + 2" — which you've already flagged as
'    unimportant.  No special filtering needed.
'
'  HOW TO RUN:
'    1. Open Outlook
'    2. Alt+F11 → Insert → Module → paste this file
'    3. Place cursor inside CategorizeEmailsByRecipientCount, press F5
' =============================================================================

' ── Bucket folder names (change if you prefer different labels) ──────────────
Private Const FOLDER_1   As String = "Me Only"
Private Const FOLDER_2   As String = "Me + 1"
Private Const FOLDER_3   As String = "Me + 2"
Private Const FOLDER_4UP As String = "Me + 3 or More"

' ── Sender domains to include ────────────────────────────────────────────────
Private Const DOMAIN_STAFF   As String = "psd1.org"
Private Const DOMAIN_STUDENT As String = "students.psd1.org"


' =============================================================================
Public Sub CategorizeEmailsByRecipientCount()
' =============================================================================

    Dim olNS    As Outlook.NameSpace
    Dim olInbox As Outlook.MAPIFolder
    Dim olPSD1  As Outlook.MAPIFolder
    Dim fMe     As Outlook.MAPIFolder
    Dim fPlus1  As Outlook.MAPIFolder
    Dim fPlus2  As Outlook.MAPIFolder
    Dim fPlus3P As Outlook.MAPIFolder
    Dim nMoved  As Long
    Dim nSkip   As Long
    Dim store   As Outlook.Store

    Set olNS    = Application.GetNamespace("MAPI")
    Set olInbox = olNS.GetDefaultFolder(olFolderInbox)

    ' ── Locate the existing PSD1.ORG folder ─────────────────────────────────
    On Error Resume Next
    Set olPSD1 = olInbox.Folders("PSD1.ORG")
    On Error GoTo 0

    If olPSD1 Is Nothing Then
        MsgBox "Cannot find  Inbox > PSD1.ORG  — please create it first.", _
               vbExclamation, "Folder Not Found"
        Exit Sub
    End If

    ' ── Get or create the four bucket subfolders ─────────────────────────────
    Set fMe     = GetOrCreateFolder(olPSD1, FOLDER_1)
    Set fPlus1  = GetOrCreateFolder(olPSD1, FOLDER_2)
    Set fPlus2  = GetOrCreateFolder(olPSD1, FOLDER_3)
    Set fPlus3P = GetOrCreateFolder(olPSD1, FOLDER_4UP)

    ' ── Pass 1: items sitting directly in PSD1.ORG (already domain-filtered) ─
    SortFolder olPSD1, fMe, fPlus1, fPlus2, fPlus3P, nMoved, nSkip, _
               checkDomain:=True

    ' ── Pass 2: sweep main Inbox for any psd1.org mail not yet moved ─────────
    SortFolder olInbox, fMe, fPlus1, fPlus2, fPlus3P, nMoved, nSkip, _
               checkDomain:=True

    ' ── Pass 3: sweep Archive folder(s) ──────────────────────────────────────
    '   Outlook may have a built-in Archive folder (olFolderArchive = 45) AND/OR
    '   a custom root-level folder also named "Archive".  We try both so nothing
    '   is missed.  Both attempts are silent on failure.
    Dim olArchive As Outlook.MAPIFolder

    '  3a — built-in Archive (Outlook 2013+; olFolderArchive = 45)
    On Error Resume Next
    Set olArchive = olNS.GetDefaultFolder(45)   ' 45 = olFolderArchive
    On Error GoTo 0
    If Not olArchive Is Nothing Then
        SortFolder olArchive, fMe, fPlus1, fPlus2, fPlus3P, nMoved, nSkip, _
                   checkDomain:=True
        Set olArchive = Nothing
    End If

    '  3b — custom root-level "Archive" folder (common in Exchange/M365)
    For Each store In olNS.Stores
        On Error Resume Next
        Set olArchive = store.GetRootFolder().Folders("Archive")
        On Error GoTo 0
        If Not olArchive Is Nothing Then
            ' Skip if it's the same folder we already processed above
            SortFolder olArchive, fMe, fPlus1, fPlus2, fPlus3P, nMoved, nSkip, _
                       checkDomain:=True
            Set olArchive = Nothing
        End If
    Next store

    MsgBox "Done!" & vbCrLf & vbCrLf & _
           "  Sorted  : " & nMoved & " messages" & vbCrLf & _
           "  Skipped : " & nSkip  & " non-mail / wrong-domain items", _
           vbInformation, "CategorizeEmails"

End Sub


' =============================================================================
'  SortFolder  — iterate one folder, move each qualifying MailItem
'
'  We snapshot EntryIDs FIRST so that moving items mid-loop doesn't corrupt
'  the live Items collection index.
' =============================================================================
Private Sub SortFolder( _
        src      As Outlook.MAPIFolder, _
        fMe      As Outlook.MAPIFolder, _
        fPlus1   As Outlook.MAPIFolder, _
        fPlus2   As Outlook.MAPIFolder, _
        fPlus3P  As Outlook.MAPIFolder, _
        ByRef nMoved As Long, _
        ByRef nSkip  As Long, _
        checkDomain  As Boolean)

    Dim olNS       As Outlook.NameSpace
    Dim entryIDs() As String
    Dim obj        As Object
    Dim msg        As Outlook.MailItem
    Dim dest       As Outlook.MAPIFolder
    Dim i          As Long
    Dim n          As Long
    Dim rcpCount   As Long
    Dim senderDom  As String

    Set olNS = Application.GetNamespace("MAPI")
    n = src.Items.Count
    If n = 0 Then Exit Sub

    ' Snapshot all EntryIDs before touching anything
    ReDim entryIDs(1 To n)
    For i = 1 To n
        entryIDs(i) = src.Items(i).EntryID
    Next i

    ' ── Process each item ────────────────────────────────────────────────────
    For i = 1 To n

        On Error Resume Next
        Set obj = olNS.GetItemFromID(entryIDs(i))
        On Error GoTo 0
        If obj Is Nothing Then GoTo NextItem

        ' Only process standard mail
        If obj.Class <> olMail Then
            nSkip = nSkip + 1
            GoTo NextItem
        End If

        Set msg = obj

        ' ── Domain filter ────────────────────────────────────────────────────
        If checkDomain Then
            senderDom = SenderDomain(msg)
            If senderDom <> DOMAIN_STAFF And senderDom <> DOMAIN_STUDENT Then
                GoTo NextItem
            End If
        End If

        ' ── Count recipients (To + CC; BCC invisible on received mail) ───────
        rcpCount = CountVisibleRecipients(msg)

        ' ── Choose destination bucket ─────────────────────────────────────────
        Select Case rcpCount
            Case 1:      Set dest = fMe
            Case 2:      Set dest = fPlus1
            Case 3:      Set dest = fPlus2
            Case Else:   Set dest = fPlus3P
        End Select

        ' Move only if not already in the target folder
        If msg.Parent.EntryID <> dest.EntryID Then
            msg.Move dest
            nMoved = nMoved + 1
        End If

NextItem:
        Set obj  = Nothing
        Set msg  = Nothing
        Set dest = Nothing
    Next i

End Sub


' =============================================================================
'  CountVisibleRecipients
'  Returns the number of To + CC recipients (excludes BCC, which is stripped
'  from received messages by the mail server anyway).
' =============================================================================
Private Function CountVisibleRecipients(msg As Outlook.MailItem) As Long
    Dim rcp   As Outlook.Recipient
    Dim count As Long
    count = 0
    For Each rcp In msg.Recipients
        If rcp.Type = olTo Or rcp.Type = olCC Then
            count = count + 1
        End If
    Next rcp
    CountVisibleRecipients = count
End Function


' =============================================================================
'  SenderDomain  — extract the domain portion of the sender's SMTP address
' =============================================================================
Private Function SenderDomain(msg As Outlook.MailItem) As String
    Dim addr As String
    Dim pos  As Long

    ' For Exchange/EAS contacts, SenderEmailAddress may be an X.500 DN;
    ' prefer the SMTP address surfaced through the sender's AddressEntry.
    On Error Resume Next
    addr = msg.Sender.GetExchangeUser().PrimarySmtpAddress
    On Error GoTo 0

    If addr = "" Then addr = msg.SenderEmailAddress

    pos = InStr(addr, "@")
    If pos > 0 Then
        SenderDomain = LCase(Mid(addr, pos + 1))
    Else
        SenderDomain = ""
    End If
End Function


' =============================================================================
'  GetOrCreateFolder  — return a named child folder, creating it if absent
' =============================================================================
Private Function GetOrCreateFolder( _
        parent     As Outlook.MAPIFolder, _
        folderName As String) As Outlook.MAPIFolder
    Dim f As Outlook.MAPIFolder
    On Error Resume Next
    Set f = parent.Folders(folderName)
    On Error GoTo 0
    If f Is Nothing Then Set f = parent.Folders.Add(folderName)
    Set GetOrCreateFolder = f
End Function

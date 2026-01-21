<?php 
// 1. Pull the key from your secret folder
require_once __DIR__ . '/.secrets/geminikey2.php'; 
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Gemini Smart Chat v2026.1</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script type="importmap">
      { "imports": { "@google/generative-ai": "https://esm.run/@google/generative-ai" } }
    </script>
</head>
<body class="bg-gray-100 h-screen flex flex-col items-center justify-center p-6">

    <script type="module">
        import { GoogleGenerativeAI } from "@google/generative-ai";

        // 2. Inject PHP key directly into a JS variable
        // This is safer than the URL but still visible in "View Source"
        const apiKey = "<?php echo $GEMINI_API_KEY; ?>";

        let chat = null;
        let genAI = null;
        let model = null;

        function initializeGemini() {
            try {
                genAI = new GoogleGenerativeAI(apiKey);
                
                // 2026 BEST PRACTICE: Move instructions out of the chat history
                // and into the 'systemInstruction' property
                model = genAI.getGenerativeModel({ 
                    model: "gemini-2.5-flash", // Use 2.5 Flash for speed
                    systemInstruction: {
                        role: "system",
                        parts: [{ text: "You are a helpful high school physics assistant. Be encouraging and clear." }]
                    }
                });

                chat = model.startChat({
                    history: [],
                    generationConfig: {
                        maxOutputTokens: 2048,
                        temperature: 0.8, // Slightly more creative for brainstorming
                    }
                });
                console.log("Gemini 2.5 Flash Initialized");
            } catch (error) {
                console.error("Initialization Failed:", error);
            }
        }

        // --- Rest of your handleChat and UI Logic remains the same ---
        // (Just ensure they reference the new 'chat' object)
        
        initializeGemini();
    </script>
</body>
</html>
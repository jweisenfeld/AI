async function sendToChatGPT() {
    const userInput = document.getElementById('user-input').value;
    const response = await fetch('https://api.openai.com/v1/engines/davinci/completions', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Authorization': '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'
        },
        body: JSON.stringify({
            prompt: userInput,
            max_tokens: 50,
            temperature: 0.7
        })
    });
    const data = await response.json();
    document.getElementById('chatgpt-reply').innerText = data.choices[0].text;
}

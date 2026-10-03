// check_models.js
require('dotenv').config();

async function getAvailableModels() {
    console.log("Checking Google's servers for your available models...");
    
    const url = `https://generativelanguage.googleapis.com/v1beta/models?key=${process.env.GEMINI_API_KEY}`;
    
    try {
        const response = await fetch(url);
        const data = await response.json();
        
        // Filter for models that support generating content
        const activeModels = data.models
            .filter(m => m.supportedGenerationMethods.includes('generateContent'))
            .map(m => m.name.replace('models/', ''));
            
        console.log("\n✅ YOU CAN USE THESE MODELS:");
        console.log(activeModels.filter(m => m.includes('flash') || m.includes('pro')));
        
    } catch (err) {
        console.error("Failed to fetch models:", err);
    }
}

getAvailableModels();
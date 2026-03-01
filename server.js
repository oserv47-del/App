const express = require('express');
const http = require('http');
const { Server } = require('socket.io');
const { createClient } = require('@supabase/supabase-js');
const cors = require('cors');
const TelegramBot = require('node-telegram-bot-api');
const os = require('os');

// ---------- Telegram Bot Setup ----------
const TELEGRAM_TOKEN = '8586296193:AAEIux2yt8IZ_9grKY-V9y5Zuvb1phGxwlo';
const TELEGRAM_CHAT_ID = '5913394915';
const bot = new TelegramBot(TELEGRAM_TOKEN, { polling: false }); // polling false because we only send messages

// Helper to send messages to Telegram
async function sendTelegramMessage(text) {
    try {
        await bot.sendMessage(TELEGRAM_CHAT_ID, text);
        console.log('Telegram message sent:', text);
    } catch (err) {
        console.error('Failed to send Telegram message:', err.message);
    }
}

// Get local IP address (IPv4, non-internal)
function getLocalIP() {
    const interfaces = os.networkInterfaces();
    for (const name of Object.keys(interfaces)) {
        for (const iface of interfaces[name]) {
            if (iface.family === 'IPv4' && !iface.internal) {
                return iface.address;
            }
        }
    }
    return '127.0.0.1'; // fallback
}

// ---------- Express & Socket.IO Setup ----------
const app = express();
app.use(cors());

const server = http.createServer(app);
const io = new Server(server, {
    cors: { origin: '*' } // Allow all connections for mobile apps
});

// Supabase details
const supabaseUrl = 'https://tozmgpxuevooslhywjpc.supabase.co';
const supabaseKey = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6InRvem1ncHh1ZXZvb3NsaHl3anBjIiwicm9sZSI6ImFub24iLCJpYXQiOjE3NzIyNTAyOTQsImV4cCI6MjA4NzgyNjI5NH0.mGp5rFZZQaitt_nDHpV3qiQ35DyzRC1_2BazBvFcm3U';
const supabase = createClient(supabaseUrl, supabaseKey);

// Send local IP on server start
const localIP = getLocalIP();
sendTelegramMessage(`🚀 Server started. Local IP: ${localIP}`);

// ---------- Socket.IO Events ----------
io.on('connection', (socket) => {
    console.log('New device connected:', socket.id);
    sendTelegramMessage(`🔌 Device connected: ${socket.id}`);

    // 1. CCTV1 (Camera phone) joins
    socket.on('join-camera', async (roomId) => {
        socket.join(roomId);
        console.log(`CCTV1 (Camera) joined room: ${roomId}`);
        sendTelegramMessage(`📷 Camera joined room ${roomId} (socket: ${socket.id})`);

        // Optional Supabase tracking
        // await supabase.from('cameras').upsert({ room_id: roomId, status: 'online', socket_id: socket.id });
    });

    // 2. CCTV2 (Viewer phone) joins
    socket.on('join-viewer', (roomId) => {
        socket.join(roomId);
        console.log(`CCTV2 (Viewer) joined room: ${roomId}`);
        sendTelegramMessage(`👁️ Viewer joined room ${roomId} (socket: ${socket.id})`);

        // Notify camera that viewer is ready
        socket.to(roomId).emit('viewer-ready');
    });

    // 3. WebRTC Offer
    socket.on('offer', (data) => {
        socket.to(data.roomId).emit('offer', data.sdp);
    });

    // 4. WebRTC Answer
    socket.on('answer', (data) => {
        socket.to(data.roomId).emit('answer', data.sdp);
    });

    // 5. ICE Candidate
    socket.on('ice-candidate', (data) => {
        socket.to(data.roomId).emit('ice-candidate', data.candidate);
    });

    // Disconnect
    socket.on('disconnect', () => {
        console.log('Device disconnected:', socket.id);
        sendTelegramMessage(`❌ Device disconnected: ${socket.id}`);
    });
});

// Health check endpoint
app.get('/', (req, res) => {
    res.send('CCTV WebRTC Server is running perfectly!');
});

// Start server
const PORT = process.env.PORT || 3000;
server.listen(PORT, () => {
    console.log(`Signaling server running on port ${PORT}`);
});
const express = require('express');
const http = require('http');
const { Server } = require('socket.io');
const { createClient } = require('@supabase/supabase-js');
const cors = require('cors');

const app = express();
app.use(cors());

const server = http.createServer(app);
const io = new Server(server, {
    cors: { origin: '*' } // Allow all connections for mobile apps
});

// Aapki Supabase Details
const supabaseUrl = 'https://tozmgpxuevooslhywjpc.supabase.co';
const supabaseKey = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6InRvem1ncHh1ZXZvb3NsaHl3anBjIiwicm9sZSI6ImFub24iLCJpYXQiOjE3NzIyNTAyOTQsImV4cCI6MjA4NzgyNjI5NH0.mGp5rFZZQaitt_nDHpV3qiQ35DyzRC1_2BazBvFcm3U';
const supabase = createClient(supabaseUrl, supabaseKey);

// Real-time WebRTC Signaling Logic
io.on('connection', (socket) => {
    console.log('New device connected:', socket.id);

    // 1. Jab CCTV1 (Camera phone) connect ho
    socket.on('join-camera', async (roomId) => {
        socket.join(roomId);
        console.log(`CCTV1 (Camera) joined room: ${roomId}`);
        
        // Supabase me record update kar sakte hain (Optional tracking)
        // await supabase.from('cameras').upsert({ room_id: roomId, status: 'online', socket_id: socket.id });
    });

    // 2. Jab CCTV2 (Aapka main phone) connect ho
    socket.on('join-viewer', (roomId) => {
        socket.join(roomId);
        console.log(`CCTV2 (Viewer) joined room: ${roomId}`);
        
        // Camera ko batayein ki viewer aa gaya hai, stream ready karo
        socket.to(roomId).emit('viewer-ready');
    });

    // 3. WebRTC Offer (CCTV1 se CCTV2 ya vice-versa)
    socket.on('offer', (data) => {
        socket.to(data.roomId).emit('offer', data.sdp);
    });

    // 4. WebRTC Answer
    socket.on('answer', (data) => {
        socket.to(data.roomId).emit('answer', data.sdp);
    });

    // 5. ICE Candidates (Network details exchange for P2P)
    socket.on('ice-candidate', (data) => {
        socket.to(data.roomId).emit('ice-candidate', data.candidate);
    });

    // Disconnect event
    socket.on('disconnect', () => {
        console.log('Device disconnected:', socket.id);
    });
});

// Render Health Check Route
app.get('/', (req, res) => {
    res.send('CCTV WebRTC Server is running perfectly!');
});

// Render automatically PORT assign karta hai
const PORT = process.env.PORT || 3000;
server.listen(PORT, () => {
    console.log(`Signaling server running on port ${PORT}`);
});

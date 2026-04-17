import json
import logging
from fastapi import FastAPI, WebSocket, WebSocketDisconnect
from fastapi.middleware.cors import CORSMiddleware
from typing import Dict, Any, List

# Server Setup
app = FastAPI()

# CORS configuration (Har jagah se API access allow karne ke liye)
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_methods=["*"],
    allow_headers=["*"],
)

# Global Storage
# Structure: { "room1": { "monitor": websocket_obj, "viewers": [websocket_obj1, ...] } }
rooms: Dict[str, Dict[str, Any]] = {}

@app.websocket("/")
async def websocket_endpoint(websocket: WebSocket):
    await websocket.accept()
    room_id = None
    role = None

    try:
        while True:
            data = await websocket.receive_text()
            
            try:
                msg = json.loads(data)
            except json.JSONDecodeError:
                continue

            msg_type = msg.get("type")
            r_id = msg.get("room")

            # 1. Join Room
            if msg_type == "join":
                room_id = r_id
                role = msg.get("role", "viewer")

                if room_id not in rooms:
                    rooms[room_id] = {"monitor": None, "viewers": []}

                if role == "monitor":
                    rooms[room_id]["monitor"] = websocket
                    print(f"Monitor joined room: {room_id}")
                else:
                    rooms[room_id]["viewers"].append(websocket)
                    print(f"Viewer joined room: {room_id}")
                    
                    # Agar monitor pehle se hai, toh usko offer banane ka command bhejein
                    monitor_ws = rooms[room_id]["monitor"]
                    if monitor_ws:
                        await monitor_ws.send_text(json.dumps({
                            "type": "create_offer",
                            "room": room_id
                        }))

            # 2. WebRTC Signaling (Offer, Answer, ICE Candidates)
            elif msg_type in ["offer", "answer", "candidate"]:
                if room_id and room_id in rooms:
                    if role == "monitor":
                        # Monitor se aaya hai -> Sabhi viewers ko bhejo
                        for viewer in rooms[room_id]["viewers"]:
                            await viewer.send_text(data)
                    else:
                        # Viewer se aaya hai -> Monitor ko bhejo
                        monitor_ws = rooms[room_id]["monitor"]
                        if monitor_ws:
                            await monitor_ws.send_text(data)

            # 3. Camera Switch Command
            elif msg_type == "command" or "command" in msg:
                cmd = msg.get("command")
                if cmd == "switch_camera" and room_id and room_id in rooms:
                    monitor_ws = rooms[room_id]["monitor"]
                    if monitor_ws:
                        await monitor_ws.send_text(json.dumps({"command": "switch_camera"}))

    except WebSocketDisconnect:
        # Client disconnect hone par cleanup
        if room_id and room_id in rooms:
            if role == "monitor":
                rooms[room_id]["monitor"] = None
                print(f"Monitor left room: {room_id}")
                # Sabhi viewers ko notify karein
                for viewer in rooms[room_id]["viewers"]:
                    try:
                        await viewer.send_text(json.dumps({"type": "monitor_disconnected"}))
                    except:
                        pass
            elif role == "viewer" and websocket in rooms[room_id]["viewers"]:
                rooms[room_id]["viewers"].remove(websocket)
                print(f"Viewer left room: {room_id}")

            # Agar room khali ho gaya toh usko delete kar dein
            if rooms[room_id]["monitor"] is None and len(rooms[room_id]["viewers"]) == 0:
                del rooms[room_id]
                print(f"Room {room_id} deleted")


# ------------------ HTTP API Endpoints ------------------

@app.get("/api/status")
async def get_status():
    total_connections = sum(
        (1 if r["monitor"] else 0) + len(r["viewers"]) 
        for r in rooms.values()
    )
    return {
        "status": "online",
        "rooms": len(rooms),
        "connections": total_connections
    }

@app.get("/api/rooms")
async def get_rooms():
    return [
        {
            "roomId": r_id,
            "hasMonitor": data["monitor"] is not None,
            "viewerCount": len(data["viewers"])
        }
        for r_id, data in rooms.items()
    ]

@app.get("/api/room/{room_id}")
async def get_room(room_id: str):
    if room_id in rooms:
        return {
            "roomId": room_id,
            "hasMonitor": rooms[room_id]["monitor"] is not None,
            "viewerCount": len(rooms[room_id]["viewers"])
        }
    return {"error": "Room not found"}

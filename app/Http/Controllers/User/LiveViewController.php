<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Camera;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

class LiveViewController extends Controller
{
    public function liveGrid()
    {
        $cameras = Camera::where('user_id', Auth::id())->get();

        // Daftarkan semua kamera user ke MediaMTX secara otomatis
        foreach ($cameras as $camera) {
            $this->ensureMediaMtxPath($camera);
        }

        return view('user.cameras.live_grid', compact('cameras'));
    }

    public function liveSingle(Camera $camera)
    {
        if ($camera->user_id != Auth::id()) abort(403);

        // Daftarkan kamera ini ke MediaMTX secara otomatis
        $this->ensureMediaMtxPath($camera);

        return view('user.cameras.live_single', compact('camera'));
    }

    /**
     * Memastikan jalur MediaMTX sudah aktif untuk kamera
     */
    private function ensureMediaMtxPath(Camera $camera)
    {
        if (empty($camera->rtsp_url)) return;

        $streamName = 'cam_' . $camera->id;
        $ffmpegCmd = "ffmpeg -rtsp_transport tcp -i {$camera->rtsp_url} -c:v copy -an -f rtsp rtsp://127.0.0.1:8554/{$streamName}";

        try {
            Http::timeout(2)->post("http://127.0.0.1:9997/v3/config/paths/add/{$streamName}", [
                'runOnInit' => $ffmpegCmd,
                'runOnInitRestart' => true
            ]);
        } catch (\Exception $e) {
            // Abaikan jika service belum siap atau path sudah ada
        }
    }

    public function controlPtz(\Illuminate\Http\Request $request)
    {
        // 1. Cari data kamera (Pastikan milik user yang sedang login)
        $camera = \App\Models\Camera::where('id', $request->camera_id)
                    ->where('user_id', \Illuminate\Support\Facades\Auth::id())
                    ->firstOrFail();

        // 2. Amankan semua input sebelum dikirim ke terminal Linux (WAJIB)
        $arah = escapeshellarg($request->direction);
        $ip   = escapeshellarg($camera->ip_address);
        $user = escapeshellarg($camera->camera_username ?? 'admin'); // Sesuaikan nama kolom database-mu
        $pass = escapeshellarg($camera->camera_password ?? 'admin');
        $port = escapeshellarg($camera->onvif_port ?? 8899);

        // 3. Lokasi script Python (Tambahkan escapeshellarg di sini)
        $scriptPath = escapeshellarg(base_path('ptz_control.py'));

        // 4. Rakit dan jalankan perintah terminal
        $command = "python3 {$scriptPath} {$ip} {$user} {$pass} {$arah} {$port} 2>&1";
        $output  = shell_exec($command);

        return response()->json([
            'status'  => 'success', 
            'message' => "Perintah $request->direction dikirim",
            'output'  => $output
        ]);
    }
}
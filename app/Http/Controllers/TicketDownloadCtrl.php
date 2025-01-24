<?php

namespace App\Http\Controllers;

use App\Models\Purchase;
use Barryvdh\Snappy\Facades\SnappyPdf;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;

class TicketDownloadCtrl extends Controller
{
    private function decryptCode($urlKey)
    {
        $encryptedData = urldecode($urlKey); // Decode URL-safe parameter
        $method = 'aes-256-cbc';

        try {
            $decoded = base64_decode($encryptedData); // Decode dari Base64
            list($iv, $ciphertext) = explode(':', $decoded); // Pisahkan IV dan ciphertext
            $iv = hex2bin($iv);
            $ciphertext = hex2bin($ciphertext);
            $decrypted = openssl_decrypt($ciphertext, $method, hex2bin(env("CRYPTO_PASS")), OPENSSL_RAW_DATA, $iv);
            $decrypted = JWT::decode($decrypted, new Key(env("JWT_CRYPTO_SECRET"), env("JWT_CRYPTO_ALG")));
        } catch (\Exception $e) {
            $decrypted = null;
        }

        return $decrypted;
    }

    public function index(Request $req, $urlKey)
    {
        $decrypted = $this->decryptCode($urlKey);
        if (!$decrypted) {
            return abort(404, 'URL undangan tidak valid');
        }
        $req->session()->forget('has_download');
        if ($req->session()->has('show_data') && $req->session()->get('show_data') == true) {
            $purchase = Purchase::where('id', explode('*~^|-|^~*', $decrypted->strQr)[0])->with([
                'ticket',
                'user',
                'payment',
                'visitDate',
                'seatNumber',
                'orgInv',
                'ticket.event',
                'ticket.event.org',
            ])->first();
            return view('download-ticket', [
                "decrypted" => $decrypted,
                "urlKey" => $urlKey,
                "purchase" => $purchase,
            ]);
        } else {
            return view('download-ticket', [
                "decrypted" => $decrypted,
                "urlKey" => $urlKey,
            ]);
        }
    }

    public function previewTicket(Request $req, $urlKey)
    {
        $validator = Validator::make($req->all(), [
            "unique_ticket" => "required|string|min:8",
            "g-recaptcha-response" => "required",
        ]);
        if ($validator->fails()) {
            $req->session()->flash('error', 'Kode unik dan Re-Chaptha wajib diisi. Minimal 8 karakter');
            return redirect()->back();
        }

        $decrypted = $this->decryptCode($urlKey);
        if ($decrypted == null) {
            $req->session()->flash('error', 'Maaf !!! URL anda tidak valid');
            return redirect()->back();
        }
        if ($req->unique_ticket != $decrypted->otpCode) {
            $req->session()->flash('error', 'Kode unik tiket tidak sesuai. Pastikan sekali lagi !');
            return redirect()->back();
        }
        $req->session()->flash('show_data', true);
        return redirect()->back();
    }

    public function downloadTicket(Request $req, $urlKey)
    {
        // dump($req->session()->has("has_download"), $req->session()->get('has_download'));
        if ($req->session()->has("has_download") && $req->session()->get('has_download') == true) {
            return redirect()->route('home-view-ticket', [$urlKey]);
        }
        $validator = Validator::make($req->all(), [
            "unique_ticket" => "required|string|min:8",
        ]);
        if ($validator->fails()) {
            $req->session()->flash('error', 'Kode unik wajib diisi. Minimal 8 karakter');
            return redirect()->back();
        }

        $decrypted = $this->decryptCode($urlKey);
        if ($decrypted == null) {
            $req->session()->flash('error', 'Maaf !!! URL anda tidak valid');
            return redirect()->back();
        }
        if ($req->unique_ticket != $decrypted->otpCode) {
            $req->session()->flash('error', 'Kode unik tiket tidak sesuai. Pastikan sekali lagi !');
            return redirect()->back();
        }

        $purchase = Purchase::where('id', explode('*~^|-|^~*', $decrypted->strQr)[0])->with([
            'ticket',
            'user',
            'payment',
            'visitDate',
            'seatNumber',
            'orgInv',
            'ticket.event',
            'ticket.event.org',
        ])->first();
        $pdf = SnappyPdf::loadView('pdfs.invoice-ticket-new', [
            "decrypted" => $decrypted,
            "urlKey" => $urlKey,
            "purchase" => $purchase,
        ])->setPaper('a4')->setOrientation('portrait')->setOption('enable-local-file-access', true);
        $req->session()->put("has_download", true);
        return $pdf->download('invoice.pdf');
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\OpticalOrderRequest;
use App\Models\OpticalPartnerClinic;
use App\Services\OpticalLinks\OpticalOrderPageService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The ordering page a partner clinic not on Nexus uses: private link + PIN, no account
 * (App\Services\OpticalLinks\OpticalOrderPageService). Everything here is scoped to the one
 * partner the link belongs to; a signed-in page lasts SESSION_MINUTES without use, and setting
 * a new PIN signs everyone out.
 */
class OpticalOrderPageController extends Controller
{
    public function __construct(private OpticalOrderPageService $pages) {}

    public function show(Request $request, string $token)
    {
        $partner = $this->partner($token);
        if (! $this->signedIn($request, $partner)) {
            return $this->page(['partner' => $partner, 'signedIn' => false]);
        }

        return $this->page([
            'partner' => $partner, 'signedIn' => true,
            'requests' => $this->pages->requests($partner),
            'taking' => $this->pages->canTakeOrders($partner),
        ]);
    }

    public function pin(Request $request, string $token)
    {
        $partner = $this->partner($token);
        $request->validate(['pin' => 'required|string|max:12'], ['pin.required' => 'Enter the PIN.']);
        $this->pages->checkPin($partner, (string) $request->input('pin'));
        $request->session()->regenerate();
        $request->session()->put($this->key($partner), ['at' => now()->timestamp, 'pin' => $partner->fresh()->page_pin_set_at?->timestamp]);

        return redirect()->route('order-page.show', $token);
    }

    /** The "Check lenses" button: lenses that fit the typed prescription, as JSON for the page. */
    public function lenses(Request $request, string $token)
    {
        $partner = $this->partner($token);
        if (! $this->signedIn($request, $partner)) return response()->json(['message' => 'Please enter the PIN again.', 'signed_out' => true], 401);
        $data = $request->validate(self::rxRules(), self::RX_MESSAGES);
        if (! OpticalOrderPageService::hasPowers($data)) {
            return response()->json(['options' => [], 'message' => 'Type the prescription first.']);
        }
        $options = $this->pages->lensOptions($partner, $data);
        $optical = $partner->clinic?->name ?? 'The optical centre';

        return response()->json([
            'options' => $options,
            'prices' => (bool) $partner->page_show_prices,
            'currency' => $partner->page_show_prices ? $this->pages->currency($partner) : null,
            'message' => $options ? null : 'No stock lens fits this prescription. '.$optical.' will quote a special order: you can still send the order.',
        ])->header('Cache-Control', 'no-store, private');
    }

    private const RX_MESSAGES = ['*.between' => 'Check this value: it is outside the usual range.'];

    private static function rxRules(): array
    {
        $rx = fn (float $min, float $max) => ['nullable', 'numeric', 'between:'.$min.','.$max];

        return [
            'od_sph' => $rx(-30, 30), 'od_cyl' => $rx(-10, 10), 'od_axis' => ['nullable', 'integer', 'between:0,180'], 'od_add' => $rx(0, 4),
            'os_sph' => $rx(-30, 30), 'os_cyl' => $rx(-10, 10), 'os_axis' => ['nullable', 'integer', 'between:0,180'], 'os_add' => $rx(0, 4),
        ];
    }

    public function send(Request $request, string $token)
    {
        $partner = $this->authorised($request, $token);
        $data = $request->validate(self::rxRules() + [
            'sender_name' => 'required|string|max:120',
            'patient_name' => 'required|string|max:150',
            'patient_phone' => 'nullable|string|max:50',
            'reference' => 'nullable|string|max:100',
            'pd' => ['nullable', 'numeric', 'between:40,80'],
            'lens_type' => 'nullable|string|max:60',
            'lens_choice' => 'nullable|string|max:500',
            'lens_notes' => 'nullable|string|max:1000',
            'frame_choice' => ['required', Rule::in(array_keys(OpticalOrderRequest::FRAME_CHOICES))],
            'frame_notes' => 'nullable|string|max:1000',
            'urgency' => 'nullable|in:normal,urgent',
            'notes' => 'nullable|string|max:2000',
        ], self::RX_MESSAGES + [
            'sender_name.required' => 'Enter your name, so the optical centre knows who sent it.',
            'patient_name.required' => 'Enter the patient’s name.',
        ]);
        foreach (['od', 'os'] as $eye) {
            if (filled($data[$eye.'_cyl'] ?? null) && ! filled($data[$eye.'_axis'] ?? null)) {
                return back()->withErrors([$eye.'_axis' => 'Enter the axis for the cylinder.'])->withInput();
            }
        }
        $sent = $this->pages->send($partner, $data);
        $request->session()->put('order_page_sender.'.$partner->id, $data['sender_name']);

        return redirect()->route('order-page.show', $token)->with('sent', 'Order for '.$sent->patient_name.' sent to '.($partner->clinic?->name ?? 'the optical centre').'. Follow it under Your orders.');
    }

    public function answer(Request $request, string $token, int $id)
    {
        $partner = $this->authorised($request, $token);
        $request->validate(['answer' => 'required|string|max:2000'], ['answer.required' => 'Type your answer.']);
        $this->pages->answer($partner, $id, (string) $request->input('answer'));

        return redirect()->route('order-page.show', $token)->with('sent', 'Answer sent.');
    }

    public function cancel(Request $request, string $token, int $id)
    {
        $partner = $this->authorised($request, $token);
        $this->pages->cancel($partner, $id);

        return redirect()->route('order-page.show', $token)->with('sent', 'Order cancelled.');
    }

    public function leave(Request $request, string $token)
    {
        $partner = $this->partner($token);
        $request->session()->forget($this->key($partner));

        return redirect()->route('order-page.show', $token);
    }

    /* ---------------------------------------------------------------- helpers */

    private function partner(string $token): OpticalPartnerClinic
    {
        $partner = $this->pages->partnerForToken($token);
        abort_unless($partner, 404);

        return $partner;
    }

    private function authorised(Request $request, string $token): OpticalPartnerClinic
    {
        $partner = $this->partner($token);
        if (! $this->signedIn($request, $partner)) abort(redirect()->route('order-page.show', $token)->withErrors(['pin' => 'Please enter the PIN again.']));

        return $partner;
    }

    private function signedIn(Request $request, OpticalPartnerClinic $partner): bool
    {
        $session = $request->session()->get($this->key($partner));
        $fresh = is_array($session)
            && (int) ($session['pin'] ?? 0) === (int) $partner->page_pin_set_at?->timestamp
            && now()->timestamp - (int) ($session['at'] ?? 0) < OpticalOrderPageService::SESSION_MINUTES * 60;
        if (! $fresh) {
            $request->session()->forget($this->key($partner));
            return false;
        }
        $request->session()->put($this->key($partner), ['at' => now()->timestamp] + $session);

        return true;
    }

    private function key(OpticalPartnerClinic $partner): string
    {
        return 'order_page.'.$partner->id;
    }

    private function page(array $data)
    {
        return response()->view('order-page.show', $data)->header('X-Robots-Tag', 'noindex, nofollow')->header('Cache-Control', 'no-store, private');
    }
}

<?php

namespace App\Livewire\Platform;

use App\Models\OpticalLinkPayment;
use App\Models\OpticalOrderRequest;
use App\Models\OpticalPartnerClinic;
use App\Services\OpticalLinks\OpticalLinkTermsService;
use Livewire\Component;

/**
 * Every link between an optical centre and a clinic that sends it jobs: status, terms, money and
 * disputes. The platform can pause, resume (its own pause) or end a link with a reason both see.
 */
class OpticalLinksComponent extends Component
{
    public const FILTERS = ['live' => 'Linked or paused', 'paused' => 'Paused', 'attention' => 'Needs attention', 'ended' => 'Ended', 'all' => 'All'];

    public string $filter = 'live';
    public string $search = '';
    public ?int $openId = null;
    public string $reason = '';

    public function open(?int $id): void
    {
        $this->openId = $this->openId === $id ? null : $id;
        $this->reason = '';
        $this->resetErrorBag();
    }

    public function setStatus(string $status): void
    {
        abort_unless($this->openId, 404);
        $this->resetErrorBag();
        app(OpticalLinkTermsService::class)->platformSetStatus($this->openId, $status, $this->reason, auth()->user());
        $this->reason = '';
        session()->flash('success', match ($status) {
            'paused' => 'Link paused. Neither clinic can resume it; the clinic can\'t send new jobs.',
            'active' => 'Link resumed.',
            default => 'Link ended. Jobs already sent stay on both sides.',
        });
    }

    public function render()
    {
        $links = OpticalPartnerClinic::withoutGlobalScopes()->with(['clinic:id,name', 'linkedClinic:id,name'])
            ->whereNotNull('linked_clinic_id')->orderByDesc('linked_at')->get();
        $requests = OpticalOrderRequest::whereIn('optical_partner_clinic_id', $links->pluck('id'))
            ->get(['id', 'optical_partner_clinic_id', 'status', 'order_total', 'order_paid', 'order_cancelled_at', 'updated_at'])->groupBy('optical_partner_clinic_id');
        $payments = OpticalLinkPayment::whereIn('optical_partner_clinic_id', $links->pluck('id'))
            ->get(['id', 'optical_partner_clinic_id', 'status', 'amount'])->groupBy('optical_partner_clinic_id');

        $rows = $links->map(function (OpticalPartnerClinic $link) use ($requests, $payments) {
            $jobs = $requests->get($link->id, collect());
            $billed = $jobs->where('status', 'accepted')->whereNull('order_cancelled_at');
            $pay = $payments->get($link->id, collect());
            $owed = OpticalLinkTermsService::owed($link);

            return [
                'link' => $link,
                'jobs' => $jobs->count(),
                'open' => $jobs->whereIn('status', ['sent', 'query', 'quoted', 'approved'])->count(),
                'billed' => round($billed->sum(fn ($j) => (float) $j->order_total), 2),
                'paid' => round($billed->sum(fn ($j) => (float) $j->order_paid), 2),
                'owed' => $owed,
                'over' => OpticalLinkTermsService::overLimit($link, $owed),
                'waiting' => $pay->where('status', 'pending')->count(),
                'disputed' => $pay->where('status', 'disputed')->count(),
                'last' => $jobs->max('updated_at'),
            ];
        });

        $term = mb_strtolower(trim($this->search));
        $shown = $rows->filter(function ($row) use ($term) {
            $link = $row['link'];
            $ok = match ($this->filter) {
                'live' => in_array($link->link_status, ['active', 'paused'], true),
                'paused' => $link->link_status === 'paused',
                'attention' => $row['disputed'] > 0 || $row['over'] || $link->link_paused_by_platform,
                'ended' => $link->link_status === 'ended',
                default => true,
            };

            return $ok && ($term === '' || str_contains(mb_strtolower($link->clinic?->name.' '.$link->linkedClinic?->name.' '.$link->name), $term));
        })->values();

        $open = $this->openId ? $rows->first(fn ($row) => $row['link']->id === $this->openId) : null;

        return view('livewire.platform.optical-links-component', [
            'rows' => $shown,
            'totals' => [
                'live' => $rows->filter(fn ($r) => $r['link']->link_status === 'active')->count(),
                'paused' => $rows->filter(fn ($r) => $r['link']->link_status === 'paused')->count(),
                'owed' => round($rows->filter(fn ($r) => $r['link']->link_status !== 'ended')->sum('owed'), 2),
                'disputed' => $rows->sum('disputed'),
                'over' => $rows->filter(fn ($r) => $r['over'] && $r['link']->link_status !== 'ended')->count(),
            ],
            'open' => $open,
            'openRequests' => $open ? OpticalOrderRequest::where('optical_partner_clinic_id', $open['link']->id)->latest('id')->limit(10)->get() : collect(),
            'openPayments' => $open ? OpticalLinkPayment::where('optical_partner_clinic_id', $open['link']->id)->latest('id')->limit(10)->get() : collect(),
        ])->layout('layouts.platform');
    }
}

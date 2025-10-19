<?php

namespace Modules\Reseller\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Modules\Ticketing\Models\Ticket;

class TicketController extends Controller
{
    public function index()
    {
        $tickets = Ticket::where('user_id', Auth::id())
            ->where('source', 'reseller')
            ->latest('updated_at')
            ->paginate(10);

        return view('reseller::tickets.index', compact('tickets'));
    }

    public function create()
    {
        return view('reseller::tickets.create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'subject' => 'required|string|max:255',
            'message' => 'required|string',
            'priority' => 'required|in:low,medium,high',
            'attachment' => 'nullable|file|mimes:jpg,jpeg,png,pdf,zip|max:5120',
        ]);

        $ticketData = [
            'subject' => $request->subject,
            'message' => $request->message,
            'priority' => $request->priority,
            'status' => 'open',
            'source' => 'reseller',
        ];

        $ticket = Auth::user()->tickets()->create($ticketData);

        // Only create a reply if there is an attachment
        if ($request->hasFile('attachment')) {
            $replyData = [
                'user_id' => Auth::id(),
                // Do not duplicate the message; only store the attachment
                'message' => null,
                'attachment_path' => $request->file('attachment')->store('ticket_attachments', 'public'),
            ];
            $ticket->replies()->create($replyData);
        }
        return redirect()->route('reseller.tickets.show', $ticket->id)
            ->with('success', 'تیکت شما با موفقیت ارسال شد.');
    }

    public function show(Ticket $ticket)
    {
        // Ensure user can only view their own tickets
        if (Auth::id() !== $ticket->user_id) {
            abort(403, 'شما اجازه دسترسی به این تیکت را ندارید.');
        }

        // Ensure ticket belongs to reseller source
        if ($ticket->source !== 'reseller') {
            abort(403, 'این تیکت متعلق به پنل ریسلر نیست.');
        }

        return view('reseller::tickets.show', ['ticket' => $ticket]);
    }
}

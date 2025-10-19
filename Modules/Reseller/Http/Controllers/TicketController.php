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
            'attachment' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf,txt,log,zip|max:5120',
        ]);

        $ticketData = [
            'subject' => $request->subject,
            'message' => $request->message,
            'priority' => $request->priority,
            'status' => 'open',
            'source' => 'reseller',
        ];

        $ticket = Auth::user()->tickets()->create($ticketData);

        // Create initial reply with message and optional attachment
        $replyData = [
            'user_id' => Auth::id(),
            'message' => $request->message,
        ];
        
        if ($request->hasFile('attachment')) {
            $path = $request->file('attachment')->store('ticket_attachments', 'public');
            $replyData['attachment_path'] = $path;
        }
        
        $ticket->replies()->create($replyData);
        
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

    public function reply(Request $request, Ticket $ticket)
    {
        // Ensure user can only reply to their own tickets
        if (Auth::id() !== $ticket->user_id) {
            abort(403, 'شما اجازه دسترسی به این تیکت را ندارید.');
        }

        // Ensure ticket belongs to reseller source
        if ($ticket->source !== 'reseller') {
            abort(403, 'این تیکت متعلق به پنل ریسلر نیست.');
        }

        $request->validate([
            'message' => 'nullable|string|required_without:attachment|max:10000',
            'attachment' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf,txt,log,zip|max:5120',
        ]);

        // Normalize message: trim and convert to empty string if attachment is present
        $message = trim((string)($request->input('message') ?? ''));
        if ($message === '' && !$request->hasFile('attachment')) {
            // Should not happen due to validation, but safety check
            return back()->withErrors(['message' => 'Either message or attachment is required.']);
        }

        $replyData = [
            'user_id' => Auth::id(),
            'message' => $message === '' ? '' : $message,
        ];

        if ($request->hasFile('attachment')) {
            $path = $request->file('attachment')->store('ticket_attachments', 'public');
            $replyData['attachment_path'] = $path;
        }

        $ticket->replies()->create($replyData);
        $ticket->update(['status' => 'open']);

        return back()->with('success', 'پاسخ شما با موفقیت ثبت شد.');
    }
}

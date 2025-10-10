<?php

namespace Modules\Ticketing\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Modules\Ticketing\Models\Ticket;

class TicketController extends Controller
{
    public function create()
    {
        return view('ticketing::tickets.create');

    }



    public function store(Request $request)
    {
        $request->validate([
            'subject' => 'required|string|max:255',
            'message' => 'required|string',
            'priority' => 'required|in:low,medium,high',
            'attachment' => 'nullable|file|mimes:jpg,jpeg,png,pdf,zip|max:5120',
        ]);

        // ======================================================
        // ====> اصلاحیه اصلی اینجاست: ذخیره message در تیکت <====
        // ======================================================

        // تمام اطلاعات لازم را برای ساخت تیکت جدید جمع‌آوری می‌کنیم
        $ticketData = [
            'subject' => $request->subject,
            'message' => $request->message, // <-- message را به اینجا اضافه می‌کنیم
            'priority' => $request->priority,
            'status' => 'open',
        ];

        // تیکت جدید را با تمام اطلاعات می‌سازیم
        $ticket = Auth::user()->tickets()->create($ticketData);

        // ======================================================

        // حالا اولین پاسخ را می‌سازیم
        $replyData = [
            'user_id' => Auth::id(),
            'message' => $request->message,
        ];

        if ($request->hasFile('attachment')) {
            $path = $request->file('attachment')->store('ticket_attachments', 'public');
            $replyData['attachment_path'] = $path;
        }

        // اولین پاسخ را به تیکت تازه ساخته شده اضافه می‌کنیم
        $ticket->replies()->create($replyData);

        return redirect()->route('dashboard')->with('status', 'تیکت شما با موفقیت ارسال شد.');
    }

    public function show(Ticket $ticket)
    {
        if (Auth::id() !== $ticket->user_id) {
            abort(403);
        }
        return view('ticketing::tickets.show', ['ticket' => $ticket]);
    }

    public function reply(Request $request, Ticket $ticket)
    {
        if (Auth::id() !== $ticket->user_id) {
            abort(403);
        }

        $request->validate([
            'message' => 'required|string',
            'attachment' => 'nullable|file|mimes:jpg,jpeg,png,pdf,zip|max:5120',
        ]);

        $replyData = [
            'user_id' => Auth::id(),
            'message' => $request->message,
        ];

        if ($request->hasFile('attachment')) {
            $path = $request->file('attachment')->store('ticket_attachments', 'public');
            $replyData['attachment_path'] = $path;
        }

        $ticket->replies()->create($replyData);
        $ticket->update(['status' => 'open']); // وضعیت را به "باز" تغییر می‌دهیم

        return back()->with('status', 'پاسخ شما با موفقیت ثبت شد.');
    }


//    public function store(Request $request)
//    {
//        $request->validate([
//            'subject' => 'required|string|max:255',
//            'message' => 'required|string',
//            'priority' => 'required|in:low,medium,high',
//        ]);
//
//        $ticket = Auth::user()->tickets()->create($request->only('subject', 'priority'));
//        $ticket->update(['status' => 'open']); // وضعیت را به "باز" تغییر می‌دهیم
//
//        // پیام اولیه را به عنوان اولین پاسخ ثبت می‌کنیم
//        $ticket->replies()->create([
//            'user_id' => Auth::id(),
//            'message' => $request->message,
//        ]);
//
//        return redirect()->route('dashboard')->with('status', 'تیکت شما با موفقیت ارسال شد.');
//    }



//    public function reply(Request $request, Ticket $ticket)
//    {
//        // اطمینان از اینکه کاربر فقط به تیکت‌های خودش پاسخ می‌دهد
//        if (Auth::id() !== $ticket->user_id) {
//            abort(403);
//        }
//
//        $request->validate(['message' => 'required|string']);
//
//        $ticket->replies()->create([
//            'user_id' => Auth::id(),
//            'message' => $request->message,
//        ]);
//
//        // وضعیت تیکت را به "باز" تغییر می‌دهیم چون کاربر پاسخ جدیدی داده
//        $ticket->update(['status' => 'open']);
//
//        return back()->with('status', 'پاسخ شما با موفقیت ثبت شد.');
//    }
}

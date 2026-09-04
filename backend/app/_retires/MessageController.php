<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMessageRequest;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    public function index(Request $request)
    {
        $userId = $request->user()->id;
        $boite = $request->input('boite', 'reception'); // reception | envoyes

        $query = Message::with('expediteur', 'destinataire');

        $query = $boite === 'envoyes'
            ? $query->where('expediteur_id', $userId)
            : $query->where('destinataire_id', $userId);

        return $query->orderByDesc('created_at')->paginate(min($request->integer('per_page', 20), 200));
    }

    public function store(StoreMessageRequest $request)
    {
        $message = Message::create([
            ...$request->validated(),
            'expediteur_id' => $request->user()->id,
        ]);

        return response()->json($message->load('expediteur', 'destinataire'), 201);
    }

    public function markAsRead(Request $request, Message $message)
    {
        abort_unless($message->destinataire_id === $request->user()->id, 403);

        $message->update(['lu_at' => now()]);

        return response()->json($message);
    }

    public function destinataires(Request $request)
    {
        return User::where('id', '!=', $request->user()->id)->orderBy('name')->get(['id', 'name', 'email']);
    }
}

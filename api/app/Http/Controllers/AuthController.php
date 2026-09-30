<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\JwtService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function __construct(private JwtService $jwt)
    {
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            return response()->json(['message' => 'Credenciales invalidas'], 422);
        }

        $issued = $this->jwt->issue($user);

        return response()->json([
            'token' => $issued['token'],
            'expires_in' => $issued['expires_in'],
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
            // El canal privado que le corresponde a este usuario. Lo manda el
            // backend para que el front no tenga que construirlo a mano.
            'channel' => 'private-user.'.$user->id,
        ]);
    }

    /**
     * Prueba que el middleware hizo su trabajo: el userId sale de
     * $request->attributes, no de una consulta ni de la sesion.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user_id_from_request_attributes' => $request->attributes->get('userId'),
            'user' => $request->user()->only(['id', 'name', 'email']),
            'channel' => 'private-user.'.$request->attributes->get('userId'),
        ]);
    }
}

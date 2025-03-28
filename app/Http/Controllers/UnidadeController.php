<?php

namespace App\Http\Controllers;

use App\Recibo;
use App\Setor;
use App\Solicitacao;
use App\Unidade;
use App\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

class UnidadeController extends Controller
{
    public function cadastrar($id)
    {
        $setor = Setor::find($id);
        return view('unidade.unidade_create', compact('setor'));
    }

    public function listarRecibos($id)
    {
        $recibos = Recibo::where('unidade_id', $id)->get();
        $unidade = Unidade::find($id);
        return view('recibo.index', ['recibos' => $recibos, 'unidade' => $unidade]);
    }

    public function criar(Request $request)
    {
        $request['cpf'] = str_replace(['.', '-'], '', $request['cpf']);
        $request['numTel'] = str_replace(['(', ')', '-'], '', $request['numTel']);

        $usuario = new Usuario();
        $usuario->email = $request->email;
        $usuario->nome = $request->nome;
        $usuario->cpf = $request->cpf;
        $usuario->rg = '0000000';
        $usuario->data_nascimento = today();
        $usuario->matricula = '0000000000';
        $usuario->cargo_id = 3;
        $usuario->numTel = $request->numTel;
        $usuario->senha = Hash::make($request->password);

        $usuario->save();

        $credentials = ['email' => $request['email']];
        Password::sendResetLink($credentials);

        $unidade = new Unidade();
        $unidade->nome = $request->nome;
        $unidade->cep = $request->cep;
        $unidade->endereco = $request->endereco;
        $unidade->bairro = $request->bairro;
        $unidade->setor_id = $request->setor;
        $unidade->usuario_id = $usuario->id;

        $unidade->save();

        return redirect(route('index.unidade', ['id' => $request->setor]))->with('success', 'Unidade Cadastrada com Sucesso!');
    }

    public function index($id)
    {
        $unidades = Unidade::where('setor_id', $id)->get()->sortBy('nome');
        $setor = Setor::find($id);
        return view('unidade.unidade_consult', compact('unidades', 'setor'));
    }

    public function editar()
    {
        $unidades = Unidade::all()->sortBy('created_at');
        return view('unidade.unidade_index_edit', ['unidades' => $unidades]);
    }

    public function edit($id)
    {
        $unidade = Unidade::find($id);
        return view('unidade.unidade_edit', ['unidade' => $unidade]);
    }

    public function alterar(Request $request)
    {
        $unidade = Unidade::find($request->unidade_id);
        $unidade->nome = $request->nome;
        $unidade->cep = $request->cep;
        $unidade->endereco = $request->endereco;
        $unidade->bairro = $request->bairro;
        $unidade->update();
        return redirect(route('index.unidade', ['id' => $unidade->setor->id]))->with('success', 'Unidade Alterada com Sucesso!');
    }

    public function remover($id)
    {
        $unidade = Unidade::find($id);
        $solicitacaos = Solicitacao::where('unidade_id', $unidade->id)->get();
        $usuario = Usuario::find($unidade->usuario_id);

        if (count($solicitacaos) == 0) {
            $unidade->delete();
            $usuario->forceDelete();
            return redirect()->back()->with('success', 'Unidade Removida com Sucesso!');
        } else {
            return redirect()->back()->with('fail', 'Não é possivel remover, a unidade já possui solicitações.');
        }
    }

}

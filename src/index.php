<?php

declare(strict_types=1);

const TIPOS = array("normal", "preferencial");


function agora(): DateTimeImmutable{

  return new DateTimeImmutable("now", new DateTimeZone("-03:00"));

}

function params(): array{

  static $params = null;

  if($params === null){
    $arquivo = __DIR__."/../variante/params.json";
    $params = json_decode((string)file_get_contents($arquivo), true, 512, JSON_THROW_ON_ERROR);
  }

  return $params;

}

function conexao(): PDO{

  static $db = null;

  if($db !== null){
    return $db;
  }

  $diretorio = "/data";

  if(!is_dir($diretorio) || !is_writable($diretorio)){
    error_log("AVISO: /data indisponível, usando diretório temporário (sem persistência real)");
    $diretorio = sys_get_temp_dir();
  }

  $db = new PDO("sqlite:".$diretorio."/fila.sqlite", null, null, array(
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
  ));

  $db->exec("PRAGMA busy_timeout = 5000");

  $db->exec("CREATE TABLE IF NOT EXISTS `senhas` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `dia` TEXT NOT NULL,
    `seq` INTEGER NOT NULL,
    `codigo` TEXT NOT NULL,
    `tipo` TEXT NOT NULL,
    `emissao` TEXT NOT NULL,
    `status` TEXT NOT NULL,
    `chamada_em` TEXT,
    `ordem_painel` INTEGER,
    UNIQUE (`dia`, `seq`)
  )");

  $db->exec("CREATE TABLE IF NOT EXISTS `estado` (`chave` TEXT PRIMARY KEY, `valor` INTEGER NOT NULL)");

  return $db;

}

function transacao(callable $funcao): array{

  $db = conexao();
  $db->exec("BEGIN IMMEDIATE");

  try{

    $resultado = $funcao($db);
    $db->exec("COMMIT");

    return $resultado;

  }catch(Throwable $e){

    $db->exec("ROLLBACK");
    throw $e;

  }

}

function lerEstado(PDO $db, string $chave): int{

  $sql = "SELECT `valor` FROM `estado` WHERE `chave` = :chave";
  $sql = $db->prepare($sql);
  $sql->bindValue(":chave", $chave);
  $sql->execute();
  $valor = $sql->fetchColumn();

  return ($valor === false ? 0 : (int)$valor);

}

function gravarEstado(PDO $db, string $chave, int $valor): void{

  $sql = "INSERT INTO `estado` (`chave`, `valor`) VALUES (:chave, :valor) ON CONFLICT(`chave`) DO UPDATE SET `valor` = excluded.`valor`";
  $sql = $db->prepare($sql);
  $sql->bindValue(":chave", $chave);
  $sql->bindValue(":valor", $valor, PDO::PARAM_INT);
  $sql->execute();

}

function buscarPorId(PDO $db, int $id): array{

  $sql = "SELECT * FROM `senhas` WHERE `id` = :id";
  $sql = $db->prepare($sql);
  $sql->bindValue(":id", $id, PDO::PARAM_INT);
  $sql->execute();

  return $sql->fetch(PDO::FETCH_ASSOC);

}

function buscarPorCodigo(PDO $db, string $codigo): ?array{

  $sql = "SELECT * FROM `senhas` WHERE `codigo` = :codigo ORDER BY `id` DESC LIMIT 1";
  $sql = $db->prepare($sql);
  $sql->bindValue(":codigo", $codigo);
  $sql->execute();
  $senha = $sql->fetch(PDO::FETCH_ASSOC);

  return ($senha === false ? null : $senha);

}

function buscarPrimeiraAguardando(PDO $db, string $tipo): ?array{

  $sql = "SELECT * FROM `senhas` WHERE `status` = 'aguardando' AND `tipo` = :tipo ORDER BY `id` ASC LIMIT 1";
  $sql = $db->prepare($sql);
  $sql->bindValue(":tipo", $tipo);
  $sql->execute();
  $senha = $sql->fetch(PDO::FETCH_ASSOC);

  return ($senha === false ? null : $senha);

}

function formatar(array $senha): array{

  $saida = array(
    "codigo" => $senha["codigo"],
    "tipo" => $senha["tipo"],
    "emissao" => $senha["emissao"],
    "status" => $senha["status"]
  );

  if($senha["chamada_em"] !== null){
    $saida["chamada_em"] = $senha["chamada_em"];
  }

  return $saida;

}

function marcarChamada(PDO $db, int $id): void{

  $sql = "SELECT COALESCE(MAX(`ordem_painel`), 0) + 1 FROM `senhas`";
  $sql = $db->prepare($sql);
  $sql->execute();
  $ordem = (int)$sql->fetchColumn();

  $sql = "UPDATE `senhas` SET `status` = 'chamada', `chamada_em` = :chamadaEm, `ordem_painel` = :ordem WHERE `id` = :id";
  $sql = $db->prepare($sql);
  $sql->bindValue(":chamadaEm", agora()->format(DATE_ATOM));
  $sql->bindValue(":ordem", $ordem, PDO::PARAM_INT);
  $sql->bindValue(":id", $id, PDO::PARAM_INT);
  $sql->execute();

}

function emitir(): array{

  $dados = json_decode((string)file_get_contents("php://input"), true);

  $tipo = null;
  if(is_array($dados) && isset($dados["tipo"])){
    $tipo = $dados["tipo"];
  }

  if(!in_array($tipo, TIPOS, true)){
    return array(422, array("erro" => "tipo_invalido"));
  }

  return transacao(function(PDO $db) use ($tipo): array{

    $momento = agora();
    $dia = $momento->format("Y-m-d");
    $params = params();

    $sql = "SELECT COALESCE(MAX(`seq`), 0) + 1 FROM `senhas` WHERE `dia` = :dia";
    $sql = $db->prepare($sql);
    $sql->bindValue(":dia", $dia);
    $sql->execute();
    $seq = (int)$sql->fetchColumn();

    $codigo = sprintf("%s%03d", $params["PREFIXO"], $seq);

    $sql = "INSERT INTO `senhas` (`dia`, `seq`, `codigo`, `tipo`, `emissao`, `status`) VALUES (:dia, :seq, :codigo, :tipo, :emissao, 'aguardando')";
    $sql = $db->prepare($sql);
    $sql->bindValue(":dia", $dia);
    $sql->bindValue(":seq", $seq, PDO::PARAM_INT);
    $sql->bindValue(":codigo", $codigo);
    $sql->bindValue(":tipo", $tipo);
    $sql->bindValue(":emissao", $momento->format(DATE_ATOM));
    $sql->execute();

    return array(201, formatar(buscarPorId($db, (int)$db->lastInsertId())));

  });

}

function proxima(): array{

  return transacao(function(PDO $db): array{

    $razao = (int)params()["RAZAO_PREFERENCIAL"];

    $preferencial = buscarPrimeiraAguardando($db, "preferencial");
    $normal = buscarPrimeiraAguardando($db, "normal");

    if($preferencial === null && $normal === null){
      return array(404, array("erro" => "fila_vazia"));
    }

    $seguidas = lerEstado($db, "pref_seguidas");

    $usaPreferencial = $preferencial !== null && ($normal === null || $seguidas < $razao);
    $escolhida = $usaPreferencial ? $preferencial : $normal;

    gravarEstado($db, "pref_seguidas", $usaPreferencial ? $seguidas + 1 : 0);
    marcarChamada($db, (int)$escolhida["id"]);

    return array(200, formatar(buscarPorId($db, (int)$escolhida["id"])));

  });

}

function transicao(string $codigo, string $acao): array{

  return transacao(function(PDO $db) use ($codigo, $acao): array{

    $senha = buscarPorCodigo($db, $codigo);

    if($senha === null){
      return array(404, array("erro" => "senha_nao_encontrada"));
    }

    $id = (int)$senha["id"];

    if($acao === "cancelar"){

      if($senha["status"] !== "aguardando"){
        return array(409, array("erro" => "senha_nao_aguardando"));
      }

      $sql = "UPDATE `senhas` SET `status` = 'cancelada' WHERE `id` = :id";
      $sql = $db->prepare($sql);
      $sql->bindValue(":id", $id, PDO::PARAM_INT);
      $sql->execute();

    }else{

      if($senha["status"] !== "chamada"){
        return array(409, array("erro" => "senha_nao_chamada"));
      }

      if($acao === "concluir"){

        $sql = "UPDATE `senhas` SET `status` = 'concluida' WHERE `id` = :id";
        $sql = $db->prepare($sql);
        $sql->bindValue(":id", $id, PDO::PARAM_INT);
        $sql->execute();

      }else{

        marcarChamada($db, $id);

      }

    }

    return array(200, formatar(buscarPorId($db, $id)));

  });

}

function painel(): array{

  $db = conexao();

  $sql = "SELECT * FROM `senhas` WHERE `ordem_painel` IS NOT NULL ORDER BY `ordem_painel` DESC LIMIT 5";
  $sql = $db->prepare($sql);
  $sql->execute();
  $linhas = $sql->fetchAll(PDO::FETCH_ASSOC);

  $chamadas = [];
  foreach($linhas as $valueLinha){
    $chamadas[] = formatar($valueLinha);
  }

  return array(200, array("chamadas" => $chamadas));

}

header("Content-Type: application/json; charset=utf-8");

$metodo = $_SERVER["REQUEST_METHOD"];
$rota = rtrim((string)parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH), "/");

if($rota === ""){
  $rota = "/";
}

$capturaRota = [];

try{

  if($metodo === "GET" && $rota === "/healthz"){

    $retorno = array(200, array("status" => "ok"));

  }elseif($metodo === "POST" && $rota === "/senhas"){

    $retorno = emitir();

  }elseif($metodo === "GET" && $rota === "/senhas/proxima"){

    $retorno = proxima();

  }elseif($metodo === "GET" && $rota === "/painel"){

    $retorno = painel();

  }elseif($metodo === "POST" && preg_match('#^/senhas/([^/]+)/(concluir|rechamar|cancelar)$#', $rota, $capturaRota) === 1){

    $retorno = transicao(urldecode($capturaRota[1]), $capturaRota[2]);

  }else{

    $retorno = array(404, array("erro" => "rota_nao_encontrada"));

  }

}catch(Throwable $e){

  error_log((string)$e);
  $retorno = array(500, array("erro" => "erro_interno"));

}

http_response_code($retorno[0]);
echo json_encode($retorno[1], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
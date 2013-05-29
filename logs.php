#!/usr/bin/env php
<?php
/**
 * Leio os logs do Postfix e retorno um relatório informando o status do e-mail
 * enviado e seu código de retorno conforme o RFC821
 *
 * http://tools.ietf.org/html/rfc821#page-33
 *
 * @author  Thiago Paes <mrprompt@gmail.com>
 * @package Logs
 * @license MIT
 */
error_reporting(E_ALL ^ E_NOTICE);

ini_set('pcre.backtrack_limit', 100000000);
ini_set('pcre.recursion_limit', 100000000);

// caminho do arquivo de log
define('MAIL_LOG', '/var/log/mail.log');

// Crônometro com microtime, para calcular o tempo de execução do script
function cronometro()
{
    $sec = explode(" ", microtime());

    return $sec[1] + $sec[0];
}

// startando o cronômetro
$inicio = cronometro();

// inicio o objeto DateTime para poder ter a data atual e diminuir X dias
$_data = new DateTime();

// data atual
$_hoje = $_data->format('Y-m-d');

// data limite (hoje)
$_dataFinal  = $_data->format('M d');

// data anteriores limite (X dia atrás)
$_data->sub(new DateInterval('P1D'));
$_dataInicial = $_data->format('M d');

// variáveis de retorno
$_conteudo  = null;
$arquivos   = 0;

// percorro o diretório com os arquivos de log
$_diretorio = new DirectoryIterator(dirname(MAIL_LOG));

foreach ($_diretorio as $_arquivo) {
    $_filtro = MAIL_LOG . "(\.[[:digit:]]+)?$";
    $_valido = preg_match("{^{$_filtro}$}i", $_arquivo->getPathname());

    if ($_arquivo->isFile() && $_valido === 1) {
        // incremento o contador de arquivos
        $arquivos++;

        // abrindo o arquivo de log
        $_conteudo .= file_get_contents($_arquivo->getPathname());
    }
}

// busco todos os message-id do log para poder tratar cada e-mail em separado
$_filtro = ""
         . "^([[:alpha:]]{3}[[:space:]]+[[:digit:]]{1,2}[[:space:]]+" // data
         . "([[:digit:]]{2}:[[:digit:]]{2}:[[:digit:]]{2}))" // data
         . "[[:space:]]([[:alnum:]]+)[[:space:]]" // nome da máquina
         . "(postfix\/cleanup\[[[:digit:]]+\]:)[[:space:]]" // smtp ou erro
         . "([[:alnum:]]+:)[[:space:]]" // ID da mensagem na fila
         . "message-id=<([^>]+){1}>$"; // message-id
preg_match_all("/{$_filtro}/im", $_conteudo, $result_ids);

// gero uma array com os resultados dos message-id pq usar array_search é lento
$_messagesIds = array();
$_linhas      = count($result_ids[0]);

for ($i=0; $i < $_linhas; $i++) {
    $_messagesIds[ substr($result_ids[5][$i], 0, -1) ] = $result_ids[6][$i];
}

// filtro as linhas com os e-mails enviados do log do Postfix
$_filtro = ""
         //. "^([[:alpha:]]{3}[[:space:]]+[[:digit:]]{1,2}[[:space:]]+" // data
         . "^(({$_dataInicial}|{$_dataFinal})[[:space:]]" // data
         . "([[:digit:]]{2}:[[:digit:]]{2}:[[:digit:]]{2}))" // hora
         . "[[:space:]]([[:alnum:]]+)[[:space:]]" // nome da máquina
         . "(postfix\/smtp\[[[:digit:]]+\]:)[[:space:]]" // smtp ou erro
         . "([[:alnum:]]+:)[[:space:]]" // ID da mensagem na fila
         . "to=<([^>]+){1}>" // destinatário
         . "(.+)" // pode ter qualquer coisa antes do status
         . "status=(.+)[[:space:]]" // status da mensagem
         . "\((.+)\)$"; // restante do log, pode ter o código RFC ou msg de erro
preg_match_all("/{$_filtro}/im", $_conteudo, $result);

// contos os resultados obtidos na ER
$_linhas = count($result[0]);

// percorro o resultado dos logs
$contador   = array();
$resultado  = array();

for ($i=0; $i < $_linhas; $i++) {
    // código padrão 451: Requested action aborted: error in processing.
    $_codigo = 451;

    // busco por um código RFC - três digitos e seguido de espaço.
    $filtro = '([[:digit:]]{3})[[:space:]](.*)';
    preg_match("/{$filtro}/i", $result[10][$i], $statusTmp);

    if (isset($statusTmp[0])) {
        $_codigo = substr($statusTmp[0], 0, stripos($statusTmp[0], ' '));
    }

    // uso a classe DateTime para formatar a data
    $_data      = new DateTime($result[1][$i]);
    $_mailData  = $_data->format('d/m/Y H:i:s');
    
    // recupero o status do e-mail (sent, bounced ou deferred)
    $_tmpstatus = explode(' ', $result[9][$i]);
    $_status    = $_tmpstatus[0];
    
    // removo o último caracter do id a messagem, q é um ':'
    $_queueid   = substr($result[6][$i], 0, -1);
    
    // busco o message-id gerado na iteração anterior
    $_posarroba = stripos($_messagesIds[$_queueid], '@');
    $_messageid = substr($_messagesIds[$_queueid], 0, $_posarroba);
    
    // e-mail de destino
    $_destinatario = $result[7][$i];

    // formato a saída
    $resultado[] = array($_queueid,     // ID da fila
                         $_messageid,   // message-id
                         $_destinatario,// destinatário
                         $_mailData,    // data do envio
                         $_status,      // status da entrega
                         $_codigo);     // código da entrega

    // contabilizando cada status
    if (isset($contador[ $_status ])) {
        $contador[ $_status ]++;
    } else {
        $contador[ $_status ] = 1;
    }
}

// gero uma linha com as estatísticas de envio de cada status
$stats = PHP_EOL;

foreach ($contador as $statusNome => $statusTotal) {
    $stats .= ucfirst($statusNome) 
           . ': ' 
           . number_format($statusTotal, 0, '.', '.') . ' - ';
}

// gero as colunas com cada linha do resultado
$linha = null;

foreach ($resultado as $dados) {
    $linha .= $dados[0] . '  '
           .  $dados[1] . '  '
           .  str_pad($dados[2], 40, '  ', STR_PAD_RIGHT)
           .  $dados[3] . ' '
           .  str_pad($dados[4], 10, '  ', STR_PAD_RIGHT)
           .  $dados[5]
           .  PHP_EOL;    
}

// paro o cronômetro e calculo o tempo de execução
$fim    = cronometro();
$tempo  = number_format(($fim - $inicio), 6);

echo $linha . substr($stats, 0, -3) 
     . " - Arquivos(s) {$arquivos} - Tempo: {$tempo} segs." . PHP_EOL;

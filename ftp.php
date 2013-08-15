#!/usr/bin/php
<?php
	error_reporting(E_ERROR | E_PARSE);
	if(!@include 'config.php') die("Arquivo de configuração config.php não encontrado.\n\n");

	#require utils/color.php
	require_once "utils/color.php";
	use Utils\Color;

	echo "\n\n\n";
	echo "**********************************\n";
	echo "Iniciado em " . date("d/m/Y H:i:s") . "\n";
	echo "**********************************\n\n";

	if(file_exists(PID)) {
		$timepid = file_get_contents(PID);
		$time_diff = mktime()-$timepid;

		if($time_diff<PID_WAIT_TIME) die("Já existe um processo em andamento. Tente novamente em " . (PID_WAIT_TIME-$time_diff) . " segundos.\n");
	}

	file_put_contents(PID, mktime());



	/**
	 * REMOVE AS BARRAS FINAIS PARA PADRONIZAR OS NOMES DOS DIRETORIOS
	 */
	while(substr($FTP_ROOT, -1)=="/") $FTP_ROOT = substr($FTP_ROOT, 0, -1);
	while(substr($FTP_DOWNLOAD, -1)=="/") $FTP_DOWNLOAD = substr($FTP_DOWNLOAD, 0, -1);
	while(substr($FTP_DOWNLOADED, -1)=="/") $FTP_DOWNLOADED = substr($FTP_DOWNLOADED, 0, -1);


	/**
	 * CRIA AS CONSTANTES DA CONFIGURACAO
	 * (Isso já está parcialemente preparado para o sistema de multiplas configurações)
	 */
	define("FTP_HOST", $FTP_HOST); unset($FTP_HOST);
	define("FTP_USER", $FTP_USER); unset($FTP_USER);
	define("FTP_PASS", $FTP_PASS); unset($FTP_PASS);
	define("FTP_ROOT", $FTP_ROOT); unset($FTP_ROOT);
	define("SLOTS", $FTP_SLOTS); unset($FTP_SLOTS);
	define("DOWNLOAD", $FTP_DOWNLOAD); unset($FTP_DOWNLOAD);
	define("DOWNLOADED", $FTP_DOWNLOADED); unset($FTP_DOWNLOADED);


	/**
	 * CRIA AS PASTAS NECESSARIAS
	 */
	if(!file_exists(DOWNLOAD)) mkdir(DOWNLOAD, 0755, true);
	if(!file_exists(DOWNLOAD)) die("Não foi possível criar pasta de download\n");

	if(!file_exists(DOWNLOADED)) mkdir(DOWNLOADED, 0755, true);
	if(!file_exists(DOWNLOADED)) die("Não foi possível criar pasta de baixados\n");



	/**
	 * FUNCOES
	 */
	function listaRecursiva($resource, $directory) { 
		$files = array();
		$dirs = array();
		$files_complete = array();
		ftp_chdir($resource, $directory);
		$entradas = ftp_rawlist($resource, ".", TRUE);

		$pasta_raiz = $directory;
		if(substr($directory, -1)!="/") $directory.="/";
		foreach($entradas as $entrada) {
			if(empty($entrada)) continue;
			if(substr($entrada, -1)==":") {
				$pasta_raiz = $directory.substr($entrada, 0, -1);
				continue;
			}

			$item = array();
			$chunks = preg_split("/\s+/", $entrada); 
			list($item['rights'], $item['number'], $item['user'], $item['group'], $item['size'], $item['month'], $item['day'], $item['time']) = $chunks; 
			array_splice($chunks, 0, 8);
			$filename = $pasta_raiz . "/" . implode(" ", $chunks);

			if($item['rights']{0}=="d") {
				$dirs[] = $filename;
			} else {
				$files[] = $filename;
				$files_complete[] = array("file"=>$filename, "size"=>$item['size']);
			}
		}

		return array("dirs"=>$dirs, "files"=>$files, "files_complete"=>$files_complete);
	}


	function criaConexao($localFile, $remoteFile, $resume=0) {
		$resume = intval($resume);
		$curl = curl_init();
		curl_setopt($curl, CURLOPT_URL, $remoteFile); #input
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($curl, CURLOPT_FILE, $localFile); #output
		curl_setopt($curl, CURLOPT_USERPWD, FTP_USER . ":" . FTP_PASS);	
		//curl_setopt($curl, CURLOPT_FRESH_CONNECT, true);
		if($resume>0) {
			curl_setopt($curl, CURLOPT_RESUME_FROM, $resume);
		}
		return $curl;
	}

	function verbose($mensagem) {
		echo Color::set($mensagem) . "\n";
	}

	function baixarArquivo($arquivo) {
		global $downloadControle, $mh;
		$url = "ftp://" . FTP_HOST . "/" . $arquivo['file'];
		$key = md5($url);

		// Abre o arquivo para escrita
		$fo = fopen(DOWNLOAD."/".$arquivo['file'], 'a');

		// Cria uma conexão curl
		$c = criaConexao($fo, $url, $arquivo['resume']);

		// Adiciona ao controle de download
		$downloadControle[$key] = array(
			"ftp_url"=>$url, 
			"local_file"=>DOWNLOAD."/".$arquivo['file'], 
			"file_handle"=>$fo, 
			"curl" => $c,
			"arquivo"=>$arquivo['file'], 
			"resume"=>$arquivo['resume']
		);

		// Adiciona ao Multi Handle
		curl_multi_add_handle($mh,$c);

		// Exibe mensagem no terminal
		$mensagem = "Iniciando ";
		if($arquivo['resume']>0) $mensagem = "Continuando ";
		verbose(mktime() . " {$mensagem} {$arquivo['file']}");
	}



    // Faz login
    echo "*** Conexao ***\n";
	$conns = array();
	$slots = array();
	$slotFile = array();
    for($x=0; $x<SLOTS; $x++) {
		$slots[$x] = null;
		$slotFile[$x] = null;
    }
    $conn_id = ftp_connect(FTP_HOST);
    ftp_login($conn_id, FTP_USER, FTP_PASS);
    ftp_set_option($conn_id, FTP_TIMEOUT_SEC, 86400);
	echo "\n";


	// Pega todas as entradas
	$lista = listaRecursiva($conn_id, FTP_ROOT);

	// Mata a conexão depois de pegar a lista
	ftp_close($conn_id);


	// Cria pastas
	echo "*** Criar diretorios ***\n";
	$base = DOWNLOAD.'/';
	@mkdir($base.str_replace("./", "", FTP_ROOT."/"), 0755, true);
	@mkdir(DOWNLOADED.'/'.str_replace("./", "", FTP_ROOT."/"), 0755, true);
	foreach($lista['dirs'] as $file) {
		if(strpos($file, " -> ") !== false) continue;
		if(file_exists($base.$file)) continue;

		if(!@mkdir($base.$file, 0755, true)) {
			echo "[Erro Diretorio] {$base}{$file}\n";
		} else {
			echo "Diretorio Criado: {$base}{$file}\n";
		}
		mkdir(DOWNLOADED.'/'.$file, 0755, true);
	}
	echo "\n";



	echo "*** Criar lista de download ***\n";
	$baixar = array();
	foreach($lista['files_complete'] as $file_complete) {
		$file = $file_complete['file'];
		$size = $file_complete['size'];
		if(strpos($file, " -> ") !== false) continue;

		if(file_exists($base.$file) && filesize($base.$file)==intval($size)) {
			verbose("<red>[Ignorando]</red> <blue>{$base}</blue><green>{$file}</green>");
			continue;
		}

		if(file_exists(DOWNLOADED.'/'.$file)) {
			echo "[Ja baixado] {$base}{$file}\n";
			continue;
		}

		$resume = 0;
		if(file_exists($base.$file)) {
			$resume = filesize($base.$file);
		}

		$baixar[] = array(
			"file" => $file,
			"resume" => $resume
		);

	}
	echo "\n";



	echo "*** Processo de download ***\n";

	// Cria o Multi CURL
	$mh = curl_multi_init();


	/**
	 * Variavel de controle de informações
	 */
	$controle = array(
		"totalBaixar" => count($baixar),
		"baixados" => 0,
	);

	$downloadControle = array();
	$slots = SLOTS;
	if($slots>$controle['totalBaixar']) $slots = $controle['totalBaixar'];


	

	// adiciona um arquivo por slot
	for($x=0; $x<$slots; $x++) {
		baixarArquivo(array_shift($baixar));
	}


	// Executa o download
	$lastScreen = 0;
	$running=null;
	do {
		$screen_cols = intval(exec('tput cols'));
		$screen_lines = intval(exec('tput lines'));
		$now_ms = microtime(true);
		if(($now_ms-$lastScreen)>0.500) {
			$lastScreen = microtime(true);
			// Escreve na tela a cada 1 segundo
			fwrite(STDOUT, "\033[2J\n");
			fwrite(STDOUT, "Faltam " . ($controle['totalBaixar']-$controle['baixados']) . " de " . $controle['totalBaixar'] . "\n");
			fwrite(STDOUT, "\n");
			fwrite(STDOUT, "\n");
	
			foreach($downloadControle as $downloading) {
				$info_speed = round(curl_getinfo($downloading['curl'], CURLINFO_SPEED_DOWNLOAD)/1024);
				$info_size = curl_getinfo($downloading['curl'], CURLINFO_CONTENT_LENGTH_DOWNLOAD) + intval($downloading['resume']);
				$info_baixado = curl_getinfo($downloading['curl'], CURLINFO_SIZE_DOWNLOAD) + intval($downloading['resume']);
				$porcentagem = round(($info_baixado*100)/$info_size);

				$progresso_espaco = $screen_cols-2;
				$progresso_char = round($progresso_espaco*($porcentagem/100));
				$progresso_blank = $progresso_espaco-$progresso_char;

				$progresso = "[";
				$progresso .= str_repeat("•", $progresso_char);
				$progresso .= str_repeat(" ", $progresso_blank);
				$progresso .= "]";
				
				fwrite(STDOUT, $downloading['arquivo'] . "\n");
				fwrite(STDOUT, $progresso . "\n");
				fwrite(STDOUT, "({$info_speed} kbps) - {$porcentagem}% | {$info_baixado} de {$info_size} | RESUME: {$downloading['resume']}\n");
				fwrite(STDOUT, "\n");
			}
			flush();
		}

		// Grava o horario no PID
		file_put_contents(PID, mktime());


		// Executa o CURL
		curl_multi_exec($mh, $running);
		$ready=curl_multi_select($mh);
		

		// Verifica se alguma conexão terminou (com sucesso ou falha)
		while($done=curl_multi_info_read($mh)) {
			$info = curl_getinfo($done['handle']);
			$baixadoControle = $downloadControle[md5($info['url'])];
			
			if($info['size_download']==$info['download_content_length']) {
				$controle['baixados']++;
				curl_close($done['handle']);
				curl_multi_remove_handle($mh, $done['handle']);

				// Fecha o arquivo
				//echo mktime() . " Finalizado " . $baixadoControle['local_file'] . " - " . gettype($baixadoControle['file_handle'])  . "\n";
				fclose($baixadoControle['file_handle']);
				file_put_contents(DOWNLOADED.'/'.$baixadoControle['arquivo'], mktime());
			} else {
				//echo "[FALHA] " . $downloadControle[md5($info['url'])]['local_file'] . "\n";
				print_r($info);
				fclose($baixadoControle['file_handle']);
			}

			// Tira o arquivo da array de downloads
			unset($downloadControle[md5($info['url'])]);

			// Coloca outro arquivo para baixar
			if($baixar) {
				baixarArquivo(array_shift($baixar));
			}
		}
		
	} while($running);


	echo "\n";


	unlink(PID);
    echo "\n";
	echo "\nFIM - " . date("d/m/Y H:i:s") . "\n\n";
	curl_multi_close($mh);	
?>

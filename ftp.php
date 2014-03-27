#!/usr/bin/php
<?php
	$PRINT_CONSOLE = true;
	if($argv[1]=="background") $PRINT_CONSOLE = false;

	error_reporting(E_ERROR | E_PARSE);
	if(!@include 'config.php') die("Arquivo de configuração config.php não encontrado.\n\n");

	#require utils/color.php
	require_once "utils/color.php";
	use Utils\Color;


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
	if(isset($FTP_FINISHED)) {
		while(substr($FTP_FINISHED, -1)=="/") $FTP_FINISHED = substr($FTP_FINISHED, 0, -1);
	}

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
	if(isset($FTP_FINISHED)) define("FINISHED", $FTP_FINISHED); unset($FTP_FINISHED);


	/**
	 * CRIA AS PASTAS NECESSARIAS
	 */
	if(!file_exists(DOWNLOAD)) mkdir(DOWNLOAD, 0755, true);
	if(!file_exists(DOWNLOAD)) die("Não foi possível criar pasta de download\n");

	if(!file_exists(DOWNLOADED)) mkdir(DOWNLOADED, 0755, true);
	if(!file_exists(DOWNLOADED)) die("Não foi possível criar pasta de baixados\n");

	if(defined(FINISHED)) {
		if(!file_exists(FINISHED)) mkdir(FINISHED, 0755, true);
		if(!file_exists(FINISHED)) die("Não foi possível criar pasta de finalizados\n");		
	}


	/**
	 * CRIA ARQUIVO DE LOG
	 */
	$LOG_FILE = '';
	if(defined("LOG")) {
		$LOG_FILE = LOG . '/ftpsync_' . date('Y-m-d_H-i-s') . '.txt';
		file_put_contents($LOG_FILE, '');
	}



	/**
	 * PREPARA ESTRUTURA PARA MOVIMENTACAO DE FINALIZADOS
	 */
	$finalizados_root_niveis = count(explode("/", FTP_ROOT));
	$finalizados_array = array();



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
				if(strpos($entrada, "./")===0) $entrada = substr($entrada,2);
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
		// curl_setopt($curl, CURLOPT_FRESH_CONNECT, true);
		if($resume>0) {
			curl_setopt($curl, CURLOPT_RESUME_FROM, $resume);
		}
		return $curl;
	}

	function verbose($mensagem, $nivel="echo") {
		global $LOG_FILE;
		$nivel = explode(",", $nivel);
		
		if(array_search("echo", $nivel)!==false){
			echo Color::set($mensagem) . "\n";
		}

		if(array_search("log", $nivel)!==false && defined("LOG")){
			file_put_contents($LOG_FILE, strip_tags($mensagem)."\n", FILE_APPEND);
		}
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
			"resume"=>$arquivo['resume'],
			"size"=>$arquivo['size']
		);

		// Adiciona ao Multi Handle
		curl_multi_add_handle($mh,$c);

		// Exibe mensagem no terminal
		$mensagem = "Iniciando ";
		if($arquivo['resume']>0) $mensagem = "Continuando ";
		verbose("[{$mensagem} - " . date("H:i:s") . "] {$arquivo['file']}", "echo,log");
	}

	function moverParaFinalizados($arquivo, &$finalizados_array) {
		if(!defined("FINISHED")) return false;
		global $finalizados_root_niveis;

		// Cria a raiz nos finalizados (se não existir)
		if(!file_exists(FINISHED.'/'.FTP_ROOT)) @mkdir(FINISHED.'/'.FTP_ROOT, 0755, true);

		$pathinfo = pathinfo($arquivo);
		$dir_niveis = explode("/", $pathinfo['dirname']);
		$dir_niveis = array_slice($dir_niveis, 0, $finalizados_root_niveis+1);
		if(count($dir_niveis)==$finalizados_root_niveis+1) {
			$dir_niveis = implode("/", $dir_niveis);
			$dir_key = md5($dir_niveis);
			$finalizados_array[$dir_key]--;

			// Se chegou a zero
			if($finalizados_array[$dir_key]==0) {
				$comando = "mv '" . DOWNLOAD . "/{$dir_niveis}' '" . FINISHED . "/{$dir_niveis}'\n";
				exec($comando);
			}
		} elseif(count($dir_niveis)==$finalizados_root_niveis) {
			$comando = "mv '" . DOWNLOAD . "/{$arquivo}' '" . FINISHED . "/{$arquivo}'\n";
			exec($comando);
		}
	}



	/* Inicia */
	verbose("\n\n");
	verbose("**********************************");
	verbose("Iniciado em " . date("d/m/Y H:i:s"), "echo,log");
	verbose("**********************************");


    // Faz login
    verbose("*** Conexao ***");
	$conns = array();
	$slots = array();
	$slotFile = array();
    for($x=0; $x<SLOTS; $x++) {
		$slots[$x] = null;
		$slotFile[$x] = null;
    }
    $conn_id = ftp_connect(FTP_HOST);
    if(!$conn_id) {
    	unlink(PID);
    	die("Não foi possivel fazer a conexão\n");
    }
    ftp_login($conn_id, FTP_USER, FTP_PASS);
    ftp_set_option($conn_id, FTP_TIMEOUT_SEC, 86400);
	echo "\n";


	// Pega todas as entradas
	$lista = listaRecursiva($conn_id, FTP_ROOT);

	// Mata a conexão depois de pegar a lista
	ftp_close($conn_id);


	verbose("*** Criar lista de download ***", "echo,log");
	$baixar = array();
	foreach($lista['files_complete'] as $file_complete) {
		$file = $file_complete['file'];
		$size = $file_complete['size'];
		$pathinfo = pathinfo($file);
		
		if(strpos($file, " -> ") !== false) continue;

		// Verifica se o arquivo já existe e se é do mesmo tamanho do arquivo remoto
		if(file_exists(DOWNLOAD.'/'.$file) && filesize(DOWNLOAD.'/'.$file)==intval($size)) {
			verbose("<redbg><black>[Ignorando]</black></redbg> <blue>{$base}</blue><green>{$file}</green>", "echo,log");
			continue;
		}

		// Verifica se está em cache
		if(file_exists(DOWNLOADED.'/'.$file)) {
			verbose("[Ja baixado] {$base}{$file}", "echo,log");
			continue;
		}

		// Verifica se é para dar resume no arquivo
		$resume = 0;
		if(file_exists(DOWNLOAD.'/'.$file)) {
			$resume = filesize(DOWNLOAD.'/'.$file);
		}


		// Cria o diretorio na pasta de download e na pasta de cache
		if(!file_exists(DOWNLOAD.'/'.$pathinfo['dirname'])) {
			if(!@mkdir(DOWNLOAD.'/'.$pathinfo['dirname'], 0755, true)) {
				verbose("ERRO: Não foi possível criar o diretório de download '" . DOWNLOAD.'/'.$pathinfo['dirname'] . "'", "echo,log");
				unlink(PID); die();
			}
		}

		if(!file_exists(DOWNLOADED.'/'.$pathinfo['dirname'])) {
			if(!@mkdir(DOWNLOADED.'/'.$pathinfo['dirname'], 0755, true)) {
				verbose("ERRO: Não foi possível criar o diretório de cache '" . DOWNLOADED.'/'.$pathinfo['dirname'] . "'", "echo,log");
				unlink(PID); die();	
			}
		}

		// Cria estrutura de finalizados
		$dir_niveis = explode("/", $pathinfo['dirname']);
		$dir_niveis = array_slice($dir_niveis, 0, $finalizados_root_niveis+1);
		if(count($dir_niveis)==$finalizados_root_niveis+1) {
			$dir_niveis = implode("/", $dir_niveis);
			$dir_key = md5($dir_niveis);
			
			if(!isset($finalizados_array[$dir_key])) {
				$finalizados_array[$dir_key] = 0;
			}

			$finalizados_array[$dir_key]++;
		}


		// Adiciona a lista
		$baixar[] = array(
			"file" => $file,
			"size" => $size,
			"resume" => $resume
		);
	}
	echo "\n";



	verbose("*** Processo de download ***");

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


	// Controle de velocidade
	$underspeed = null;


	// Executa o download
	$lastScreen = 0;
	$running=null;
	$json = array(
		"timestamp" => mktime(),
		"info" => array(),
		"downloads" => array()
	);
	do {
		if($PRINT_CONSOLE) {
			$screen_cols = intval(exec('tput cols'));
			$screen_lines = intval(exec('tput lines'));
			$now_ms = microtime(true);
			if(($now_ms-$lastScreen)>0.500) {
				$lastScreen = microtime(true);
				// Escreve na tela a cada 1 segundo
				// fwrite(STDOUT, "\033[2J\n");
				// fwrite(STDOUT, "Faltam " . ($controle['totalBaixar']-$controle['baixados']) . " de " . $controle['totalBaixar'] . "\n");
				// fwrite(STDOUT, "\n");
				// fwrite(STDOUT, "\n");

				$json['info'] = array(
					'totalBaixar' => $controle['totalBaixar'],
					'baixados' => $controle['baixados'],
					'speed' => 0
				);
		
				foreach($downloadControle as $downloading) {
					$jsonDownload = array();

					$info_speed = round(curl_getinfo($downloading['curl'], CURLINFO_SPEED_DOWNLOAD)/1024);
					$info_size = curl_getinfo($downloading['curl'], CURLINFO_CONTENT_LENGTH_DOWNLOAD) + intval($downloading['resume']);
					$info_baixado = curl_getinfo($downloading['curl'], CURLINFO_SIZE_DOWNLOAD) + intval($downloading['resume']);
					$porcentagem = round(($info_baixado*100)/$info_size);
					$porcentagem_resume = round((intval($downloading['resume'])*100)/intval($downloading['size']));
					$porcentagem_real = $porcentagem-$porcentagem_resume;

					$json['info']['speed'] += $info_speed;

					$jsonDownload = array(
						'arquivo' => $downloading['arquivo'],
						'speed' => $info_speed,
						'size' => $info_size,
						'baixado' => $info_baixado,
						'porcentagem' => $porcentagem,
						'porcentagem_resume' => $porcentagem_resume,
						'porcentagem_real' => $porcentagem_real,
						'resume' => intval($downloading['resume'])
					);

					$json['downloads'][] = $jsonDownload;
				}

				file_put_contents(__DIR__.'/web/data-info.js', "Data=".json_encode($json));
			}
		}


		// Escreve Status
		if($PRINT_CONSOLE) {
			echo "\r[" . $json['info']['speed'] . 'kbps | finished: ' . ($controle['baixados']) . " | total: " . $controle['totalBaixar'] . "]";
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
			curl_close($done['handle']);
			curl_multi_remove_handle($mh, $done['handle']);
			fclose($baixadoControle['file_handle']);
			
			if($info['size_download']==$baixadoControle['size']) {
				// Adiciona +1 no controle de baixados
				$controle['baixados']++;

				// Fecha o arquivo
				verbose("\n[Finalizado - " . date("H:i:s") . "] " . $baixadoControle['arquivo'], "echo,log");
				
				// Grava o arquivo de cache
				file_put_contents(DOWNLOADED.'/'.$baixadoControle['arquivo'], mktime());

				// Move para finalizados (se a opção estiver setada)
				moverParaFinalizados($baixadoControle['arquivo'], $finalizados_array);
			} else {
				$msgDeErro = curl_error($done['handle']);
				verbose("\n[Falha - " . date("H:i:s") . "] " . $downloadControle[md5($info['url'])]['arquivo'], "echo,log");
				verbose("\t{$msgDeErro}", "echo,log");
			}

			// Tira o arquivo da array de downloads
			unset($downloadControle[md5($info['url'])]);

			// Coloca outro arquivo para baixar
			if($baixar) {
				baixarArquivo(array_shift($baixar));
			}
		}


		// Verifica velocidade
		if($json['info']['speed']<MIN_SPEED) {
			if(is_null($underspeed)) {
				$underspeed = mktime();
			} else {
				if(mktime()-$underspeed>=MIN_SPEED_SECONDS) {
					$running = false;
					$baixar = array();
					// unlink(PID);
					echo "\n\n *** STOP: Velocidade inferior a " . MIN_SPEED . "kbps por mais de " . MIN_SPEED_SECONDS . " segundos ***";
				}
			}
		} else {
			$underspeed = null;
		}
		
	} while($running || count($baixar));


	// Mata qualquer conexão ainda ativa
	foreach($downloadControle as $d) {
		curl_close($d['curl']);
		curl_multi_remove_handle($mh, $d['curl']);
	}


	echo "\n";
	file_put_contents(__DIR__.'/web/data-info.js', "Data=".json_encode(array()));
	unlink(PID);
    echo "\n\n";
	verbose("FIM - " . date("d/m/Y H:i:s"), "echo,log");
	echo "\n";
	curl_multi_close($mh);

	if(!is_null($underspeed)) exit(1);
?>

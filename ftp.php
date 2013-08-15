#!/usr/bin/php
<?php
	error_reporting(E_ERROR | E_PARSE);
	if(!@include 'config.php') die("Arquivo de configuração config.php não encontrado.\n\n");


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
		//ftp_chdir($resource, $directory);
		$entradas = ftp_rawlist($resource, $directory, TRUE);
		//ftp_chdir($resource, "/");

		foreach($entradas as $entrada) {
			if(empty($entrada)) continue;
			if(substr($entrada, -1)==":") {
				$directory = substr($entrada, 0, -1);
				continue;
			}
			//echo $entrada . "\n";

			$item = array();
			$chunks = preg_split("/\s+/", $entrada); 
			list($item['rights'], $item['number'], $item['user'], $item['group'], $item['size'], $item['month'], $item['day'], $item['time']) = $chunks; 
			array_splice($chunks, 0, 8);
			$filename = $directory . "/" . implode(" ", $chunks);
			//echo print_r($chunks) . "\n";

			//$files[] = $directory.'/'.$entrada;

			if($item['rights']{0}=="d") {
				$dirs[] = $filename;
			} else {
				$files[] = $filename;
				$files_complete[] = array("file"=>'/' . $filename, "size"=>$item['size']);
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
		echo $mensagem . "\n";
	}

	function baixarArquivo($arquivo) {
		global $downloadControle, $mh;
		$url = "ftp://" . FTP_HOST . "/" . $arquivo['file'];
		$key = md5($url);

		// Abre o arquivo para escrita
		$fo = fopen(DOWNLOAD."/".$arquivo['file'], 'a');

		// Adiciona ao controle de download
		$downloadControle[$key] = array(
			"ftp_url"=>$url, 
			"local_file"=>DOWNLOAD."/".$arquivo['file'], 
			"file_handle"=>$fo, 
			"arquivo"=>$arquivo['file'], 
			"resume"=>$arquivo['resume']
		);

		// Cria uma conexão curl
		$c = criaConexao($fo, $url, $arquivo['resume']);

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
			echo "[Ignorando] {$base}{$file}\n";
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


	$totalBaixar = count($baixar);
	$downloadControle = array();
	$baixados = 0;
	$slots = SLOTS;
	if($slots>$totalBaixar) $slots = $totalBaixar;

	// adiciona um arquivo por slot
	for($x=0; $x<$slots; $x++) {
		baixarArquivo(array_shift($baixar));
	}


	// Executa o download
	$running=null;
	do {
		file_put_contents(PID, mktime());
		while(($execrun = curl_multi_exec($mh, $running)) == CURLM_CALL_MULTI_PERFORM);
		if($execrun != CURLM_OK) break;

		while($done=curl_multi_info_read($mh)) {
			$info = curl_getinfo($done['handle']);
			$baixadoControle = $downloadControle[md5($info['url'])];
			//if($info['http_code']>=200 && $info['http_code']<300) {
			if($info['size_download']==$info['download_content_length']) {
				curl_close($done['handle']);
				curl_multi_remove_handle($mh, $done['handle']);

				// Fecha o arquivo
				echo mktime() . " Finalizado " . $baixadoControle['local_file'] . " - " . gettype($baixadoControle['file_handle'])  . "\n";
				fclose($baixadoControle['file_handle']);
				file_put_contents(DOWNLOADED.'/'.$baixadoControle['arquivo'], mktime());
			} else {
				echo "[FALHA] " . $downloadControle[md5($info['url'])]['local_file'] . "\n";
				fclose($baixadoControle['file_handle']);
				print_r($info);
			}

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

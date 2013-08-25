;(function($) {
	var FTPSync = {
		init: function() {
			var self = this;
			self.loadData();
			window.setInterval(function() {
				self.loadData();
			}, 500);
			
		},

		loadData: function() {
			var self = this;
			$.ajax({
				url: 'data-info.js',
				dataType: 'script',
				success: function(d) {
					self.showData();
				}
			})
		},

		showData: function() {
			$("#info").html("");
			for(var i in Data.downloads) {
				var porcentagem = Data.downloads[i].porcentagem;
				var porcentagem_resume = Data.downloads[i].porcentagem_resume;
				var speed = Data.downloads[i].speed;
				var resume = Data.downloads[i].resume;
				if(resume>0) {
					porcentagem = Data.downloads[i].porcentagem_real;
				}

				var $container = $("<div class=\"panel panel-default\"></div>");
					var $arquivo = $("<div class=\"panel-heading\">" + Data.downloads[i].arquivo + " <span class=\"badge\">" + speed + " kbps</span></div>");
					var $body = $("<div class=\"panel-body\" />");
						var $progresso = $('<div class="progress"><div class="progress-bar" role="progressbar" aria-valuenow="' + porcentagem + '" aria-valuemin="0" aria-valuemax="100" style="width: ' + porcentagem + '%;"><span class="sr-only">' + porcentagem + '% Complete</span></div></div>');
						if(resume>0) {
							var $progresso = $('<div class="progress"><div class="progress-bar progress-bar-success" role="progressbar" aria-valuenow="' + porcentagem_resume + '" aria-valuemin="0" aria-valuemax="100" style="width: ' + porcentagem_resume + '%;"><span class="sr-only">' + porcentagem + '% Complete</span></div><div class="progress-bar" role="progressbar" aria-valuenow="' + porcentagem + '" aria-valuemin="0" aria-valuemax="100" style="width: ' + porcentagem + '%;"><span class="sr-only">' + porcentagem + '% Complete</span></div></div>');
						}
						var $info = $("<span>" + speed + " kbps</span>");

				$container.append($arquivo);
				$body.append($progresso);
				//$body.append($info);
				$container.append($body);

				$("#info").append($container);
			}
		}
	};

	$(document).ready(function() { FTPSync.init() });
})(jQuery);
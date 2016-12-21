<!DOCTYPE html>
<html>
<head>
	<link href='https://fonts.googleapis.com/css?family=Roboto' rel='stylesheet' type='text/css'>
	<link href='https://fonts.googleapis.com/css?family=Ubuntu+Mono' rel='stylesheet' type='text/css'>
	<style type="text/css">
		body{
			font-family: 'Roboto', sans-serif;
			color: #333;
			font-weight: thin;
		}
		a {color: #760; text-decoration:none}
		#sidebar a:active {color: #000; text-decoration:none;}
		#sidebar a:visited {color: #000; text-decoration:none;}
		#sidebar a:hover {color: #00; text-decoration:none; background: #DDD}
		#sidebar a:link {color: #000; text-decoration:none;}

		#pageWrapper{
			max-width: 1024px;
			margin:auto;
		}
		#interfaceWrapper{
			width: 100%;
			display: table;
		}
		#contentWrapper{
			width: 78%;
			display: table-cell;
			padding: 0 2px;
		}
		#sidebar{
			width:20%;
			background: #eee;
			display: table-cell;
			border: 1px solid #555;
			border-radius: 15px 0 0 0;
			padding: 0 .5em 1em .5em;
		}

		#contentFoot{
			border-radius: 0 0 15px 15px;
			background: #444;
			min-height: 2em;
			margin-top: 2px;
			text-align:center;
			font-size : 50%;
			color: #AAA;
			border: solid #000;
			border-width: 0 1px 1px 1px;
		}
		#contentFoot a{
			color: #EEE;
			line-height: 3em;
		}

		#sidebar a{
			display:inline-block;
			width: 100%;
		}

		ul{
			list-style-type:none;
			padding: 0;
		}

		div.code{
			padding: 1em 0 1em 2em;
			margin: 1em 2em;
			font-family: monospace;
			white-space: pre;
			background: #DDD;
			font-family: 'Ubuntu Mono';
			overflow: auto;
			font-size: 80%;
		}
		div.indent{
			margin-left: 3em;
		}
		table.definitionList{
			border: 1px solid #666;
			table-layout:fixed;
			border-collapse:collapse;
		}
		table.definitionList th{
			padding: 0.2em;
			background: #DDD;
			text-align: right;
			border: 1px solid #666;
			vertical-align: top;
		}
		table.definitionList td{
			padding: 0.2em;
			background: #FFF;
			text-align: left;
			border: 1px solid #666;
			vertical-align: top;
		}
		div.textblock{
			margin: 0.5em 0;
		}

		p{
			margin: 0.5em 0;
		}
		ul{
			margin: 0;
		}

		h2{
			border: 1px solid #000;
			border-width: 1px 1px 0 0;
			background: #444;
			color: #FFF;
			padding: 2px;
			margin-top:0;
			border-radius: 0 15px 0 0;
			margin-left: -10px;
		}
		h3{
			background: #666;
			color: #FFF;
			padding: 2px;
		}
		h4{
			background-color: #888;
			color: #FFF;
			padding: 2px;
		}

		h5, h4{
			margin-bottom: 0.25em;
		}

		div.section{
			display:none;
			background: #CCC;
			border-radius: 0 15px 0 0;
			padding-bottom: 1em;
			padding-left: 10px;
		}
		div.section h2{
		}


	</style>

	<script type="text/javascript" src="jquery.min.js"></script>
	<script type="text/javascript">
		$(document).ready(function(){
			$('#contentWrapper').load('helpContent.html', function(){
				$('#frontpage').css('display', 'block');
				$('.section').each(function(){
					var li = $('<li></li>');
					var a = $('<a href="#"></a>');
					//alert($(this).attr('id'));
	//				var label = $(this).find('h2')[0].innerHTML;

					var me = $(this);
					a.click(function(){selectSection(me); return false;});
					a.html($(this).find('h2')[0].innerHTML);
					a.appendTo(li);
					li.appendTo($('#sidebarContents'));
				});
			});
		});

		function selectSection(element){
			$('.section').css('display', 'none');
			element.css('display', 'block');
		}
	</script>
</head>
<body>
	<div id="pageWrapper">
		<h1>dbTemplate.php</h1>
		<div id="interfaceWrapper">
			<div id="sidebar">
				<ul id="sidebarContents"></ul>
			</div>
			<div id="contentWrapper"></div>
		</div>
		<div id="contentFoot">Copyright &copy; 2015 Jacob A. Ewing.  Licensed under <a href="http://www.gnu.org/licenses/gpl-3.0.en.html">GNU GPL 3.0</a></div>
	</div>
</body>
</html>

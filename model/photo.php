<?php
//$word="Россия";
class photo{
    public $wiki; //слово запроса
    public $texta;//text body страницы
    public $htmlhead ;
    public $url ;//ссылка на изображение

function __construct($wiki="MSK_Collage_2015.png")
{
$this->wiki=$wiki;
$this->texta=$texta;
$this->htmlhead=$htmlhead;
}


function gettext($wiki="MSK_Collage_2015.png"){
    
    $input=file_get_contents("https://ru.wiktionary.org/w/api.php?action=query&prop=imageinfo&iiprop=url&format=json&titles=Файл:".$wiki);
    $this->texta=json_decode($input);
    $inputdecode=json_decode($input);
    $this->title=$inputdecode->query->pages->{'-1'}->title; 
    $this->url=$inputdecode->query->pages->{'-1'}->imageinfo[0]->url; 
    $this->htmlhead=$inputdecode->parse->headhtml->{'*'};
    $this->title=$inputdecode->parse->title;
    $this->texta=$inputdecode->parse->text->{'*'};
    $this->section=$inputdecode->parse->sections;
  //  var_dump($this->section);
return $this->title;

}


function cleantext(){
   $clean= $this->texta;
   $cleanhead=$this->htmlhead;
//форматирование head к выводу
//стили вывод в хеад
$headend=' <!-- Bootstrap CSS -->
<link href="https://cdnjs.cloudflare.com/ajax/libs/bootswatch/4.1.1/cerulean/bootstrap.min.css" rel="stylesheet"  crossorigin="anonymous">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css" rel="stylesheet"  crossorigin="anonymous">
<link href="../css/style.css" rel="stylesheet"  crossorigin="anonymous">
<link href="../css/bootstrap.min.css" rel="stylesheet"  crossorigin="anonymous">

<style>

   body {
background-image: url("http://diccionario.pro/img/light.png");
background-repeat: repeat;
}

</style>

</head>';
ob_start();
echo'
<div  class="container-fluid">
<div class="row">
    <div class="col-sm-3">
        <div class="nav-side-menu">
            <div class="brand"><a href="http://diccionario.pro" ><img src="../img/logo.png" class="brand" alt="diccionario.pro"></a></div>
            <i class="fa fa-bars fa-2x toggle-btn" data-toggle="collapse" data-target="#menu-content"></i>
            <div class="menu-list">
                <ul id="menu-content" class="menu-content collapse out"> ';
                echo'
                <li data-toggle="collapse" data-target="#'.(int)($this->section[0]->number).'" class="collapsed">
               
                <a href="#'.$this->section[0]->anchor.'"><i class="fa fa-arrow-circle-down fa-lg"></i>'.$this->section[0]->line.' <span class="arrow"></span> </a> </li>
                <ul class="sub-menu collapse" id="'.(int)($this->section[0]->number).'">
                ';




    echo'</ul>
    </div>
</div>
</div>

<div class="col-sm-9 col-sm-offset-1">
<h1 id="firstHeading" class="firstHeading" lang="ru">'.$this->title.'</h1> '.$this->url.'';



    $navig=ob_get_clean();

   
   

$cleanhead = preg_replace("#</head>#",$headend,$cleanhead);
$cleanhead = preg_replace("#<title>(.*)</title>#","<title>{$this->title}</title>",$cleanhead);
$cleanhead = preg_replace("#<body (.*?)>#","<body $1>$navig",$cleanhead);
   //форматирование текста к выводу
   $clean = preg_replace("~<a .*>Править</a>~",'',$clean);
   $clean = preg_replace('~<a href="/w/index.php.*".*>(.*?)</a>~','$1',$clean);
   $this->texta=$clean;

  //return  $clean;
  $this->htmlhead=$cleanhead;
  return  $clean;

}







}



?>


<?php
// Build the HTML for a pipeline documentation page.
// Imported by public_html/pipeline.php - pulls a markdown file from GitHub and renders.

# Details for parsing markdown file, fetched from Github
# Build the remote file path
# Special case - root docs is allow
require_once('../includes/parse_md.php');
if(substr($_SERVER['REQUEST_URI'], -3) == '.md'){
  # Clean up URL by removing .md
  header('Location: '.substr($_SERVER['REQUEST_URI'], 0, -3));
  exit;
}
$filename = 'docs/usage.md';
$md_trim_before = '# Introduction';

# Build the local and remote file paths based on whether we have a release or not
if($release !== 'dev'){
  $git_branch = $release;
  $local_fn_base = dirname(dirname(dirname(__FILE__)))."/markdown/pipelines/".$pipeline->name."/".$release."/";
} else {
  $git_branch = 'dev';
  $local_fn_base = dirname(dirname(dirname(__FILE__)))."/markdown/pipelines/".$pipeline->name."/".$pipeline->pushed_at."/";
}
$local_md_fn = $local_fn_base.$filename;
$markdown_fn = 'https://raw.githubusercontent.com/'.$pipeline->full_name.'/'.$git_branch.'/'.$filename;

# Check if we have a local copy of the markdown file and fetch if not
if(file_exists($local_md_fn)){
  $markdown_fn = $local_md_fn;
} else {
  # Build directories if needed
  if (!is_dir(dirname($local_md_fn))) {
    mkdir(dirname($local_md_fn), 0777, true);
  }
  $md_contents = file_get_contents($markdown_fn);
  if($md_contents){
    file_put_contents($local_md_fn, $md_contents);
    $markdown_fn = $local_md_fn;
  }
}
# Try to fetch the nextflow_schema.json file for the latest release, taken from pipeline.php
$gh_launch_schema_fn = dirname(dirname(__FILE__))."/api_cache/json_schema/{$pipeline->name}/{$release}.json";
$gh_launch_no_schema_fn = dirname(dirname(__FILE__))."/api_cache/json_schema/{$pipeline->name}/{$release}.NO_SCHEMA";
# Build directories if needed
if (!is_dir(dirname($gh_launch_schema_fn))) {
  mkdir(dirname($gh_launch_schema_fn), 0777, true);
}
// Load cache if not 'dev'
if((!file_exists($gh_launch_schema_fn) && !file_exists($gh_launch_no_schema_fn)) || $release == 'dev'){
  $api_opts = stream_context_create([ 'http' => [ 'method' => 'GET', 'header' => [ 'User-Agent: PHP' ] ] ]);
  $gh_launch_schema_url = "https://raw.githubusercontent.com/nf-core/{$pipeline->name}/{$release}/nextflow_schema.json";
  $gh_launch_schema_json = file_get_contents($gh_launch_schema_url, false, $api_opts);
  if(!in_array("HTTP/1.1 200 OK", $http_response_header)){
    # Remember for next time
    file_put_contents($gh_launch_no_schema_fn, '');
  } else {
    # Save cache
    file_put_contents($gh_launch_schema_fn, $gh_launch_schema_json);
  }
}

###############
# RENDER JSON SCHEMA PARAMETERS DOCS
###############
if(file_exists($gh_launch_schema_fn)){

  $raw_json = file_get_contents($gh_launch_schema_fn);
  $schema = json_decode($raw_json, TRUE);

  function print_param($level, $param_id, $param, $is_required=false){
    $is_hidden = false;
    if(array_key_exists("hidden", $param) && $param["hidden"]){
      $is_hidden = true;
    }

    # Build heading
    if(array_key_exists("fa_icon", $param)){
      $fa_icon = '<i class="'.$param['fa_icon'].' fa-fw"></i> ';
    } else {
      $fa_icon = '<i class="fad fa-circle fa-fw text-muted"></i> ';
    }
    $h_text = $param_id;
    if($level > 2){ $h_text = '<code>'.$h_text.'</code>'; }
    $heading = _h($level, $fa_icon.$h_text);

    # Description
    $description = '';
    if(array_key_exists("description", $param)){
      $description = parse_md($param['description']);
    }

    # Help text
    $help_text_btn = '';
    $help_text = '';
    if(array_key_exists("help_text", $param)){
      $help_text_btn = '
        <button class="btn btn-sm btn-outline-secondary float-right ml-2" data-toggle="collapse" href="#'.$param_id.'-help" aria-expanded="false">
          <i class="fas fa-question-circle"></i> Help
        </button>';
      $help_text = '
        <div class="collapse card schema-docs-help-text" id="'.$param_id.'-help">
          <div class="card-body small text-muted">'.parse_md($param['help_text']).'</div>
        </div>';
    }

    # Labels
    $labels = [];
    if($is_hidden){
      $labels[] = '<span class="badge badge-secondary ml-2">hidden</span>';
    }
    if($is_required){
      $labels[] = '<span class="badge badge-warning ml-2">required</span>';
    }
    if(count($labels) > 0){
      $labels_str = '<div class="float-right">'.implode(' ', $labels).'</div>';
    }

    return $labels_str.$heading.$help_text_btn.$description.$help_text;
  }

  $schema_content = '<div class="schema-docs">';
  $schema_content .= _h(1, 'Parameters');
  foreach($schema["properties"] as $param_id => $param){
    // Groups
    if($param["type"] == "object"){
      $schema_content .= print_param(2, $param_id, $param);
      // Group-level params
      foreach($param["properties"] as $child_param_id => $child_param){
        $is_required = false;
        if(array_key_exists("required", $param) && in_array($child_param_id, $param["required"])){
          $is_required = true;
        }
        $schema_content .= print_param(3, $child_param_id, $child_param, $is_required);
      }
    }
    // Top-level params
    else {
      $is_required = false;
      if(array_key_exists("required", $schema) && in_array($param_id, $schema["required"])){
        $is_required = true;
      }
      $schema_content .= print_param(3, $param_id, $param, $is_required);
    }
  }
  $schema_content .= '</div>'; // .schema-docs
}


# Configs to make relative URLs work
$src_url_prepend = 'https://raw.githubusercontent.com/'.$pipeline->full_name.'/'.$git_branch.'/'.implode('/', array_slice($path_parts, 1, -1)).'/';
$href_url_prepend = '/'.$pipeline->name.'/'.implode('/', array_slice($path_parts, 1)).'/';
$href_url_prepend = preg_replace('/\/\/+/', '/', $href_url_prepend);
$href_url_suffix_cleanup = '\.md';

$md_content_replace = array(
    array(''),
    array('# ')
);

# Styling
$md_content_replace = array(
    array('# nf-core/'.$pipeline->name.': '),
    array('# ')
);
$html_content_replace = array(
    array('<table>'),
    array('<table class="table">')
);

# Header - keywords
$header_html = '<p class="mb-0">';
foreach($pipeline->topics as $keyword){
  $header_html .= '<a href="/pipelines?q='.$keyword.'" class="badge pipeline-topic">'.$keyword.'</a> ';
}
$header_html .= '</p>';

// Highlight any search terms if we have them
if(isset($_GET['q']) && strlen($_GET['q'])){
  $title = preg_replace("/(".$_GET['q'].")/i", "<mark>$1</mark>", $title);
  $subtitle = preg_replace("/(".$_GET['q'].")/i", "<mark>$1</mark>", $subtitle);
  $header_html = preg_replace("/(".$_GET['q'].")/i", "<mark>$1</mark>", $header_html);
}

// Footer source link
$md_github_url = 'https://github.com/'.$pipeline->full_name.'/blob/'.$git_branch.'/'.$filename;


// Content will be rendered by header.php

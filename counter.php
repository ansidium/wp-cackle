<?php
class CackleCounter{
    public static function init() {
        if (is_single() || is_page()){
        }
        else{

            $api_id = get_option('cackle_apiId');
            if (empty($api_id)) {
                return;
            }

            //define('ICL_LANGUAGE_CODE','de');
            if (defined('ICL_LANGUAGE_CODE')) {
                switch (ICL_LANGUAGE_CODE){
                    case 'uk':
                        $lang_for_cackle = 'uk';
                        break;
                    case 'be':
                        $lang_for_cackle = 'be';
                        break;
                    case 'kk':
                        $lang_for_cackle = 'kk';
                        break;
                    case 'en':
                        $lang_for_cackle = 'en';
                        break;
                    case 'es':
                        $lang_for_cackle = 'es';
                        break;
                    case 'de':
                        $lang_for_cackle = 'de';
                        break;
                    case 'lv':
                        $lang_for_cackle = 'lv';
                        break;
                    case 'el':
                        $lang_for_cackle = 'el';
                        break;
                    case 'fr':
                        $lang_for_cackle = 'fr';
                        break;
                    case 'ro':
                        $lang_for_cackle = 'ro';
                        break;
                    case 'it':
                        $lang_for_cackle = 'it';
                        break;
                    case 'ru':
                        $lang_for_cackle = 'ru';
                        break;
                    default:
                        $lang_for_cackle = null;
                }

            } else {
                $lang_for_cackle = null;
            }

            ?>
            <script type="text/javascript">
                // <![CDATA[
                var nodes = document.getElementsByTagName('span');
                for (var i = 0, url; i < nodes.length; i++) {
                    if (nodes[i].className.indexOf('cackle-postid') != -1) {
                        var c_id = nodes[i].getAttribute('id').split('c');
                        nodes[i].parentNode.setAttribute('cackle-channel', c_id[1] );
                        url = nodes[i].parentNode.href.split('#', 1);
                        if (url.length == 1) url = url[0];
                        else url = url[1]
                        nodes[i].parentNode.href = url + '#mc-container';
                    }
                }


                cackle_widget = window.cackle_widget || [];
                cackle_widget.push({widget: 'CommentCount', <?php if(get_option('cackle_counter_rubrics', 1)==0) { ?> no: ' 0', one: ' 1', mult: ' {num}', <?php } ?> id: <?php echo wp_json_encode($api_id); ?><?php if ($lang_for_cackle != null) : ?>, lang: <?php echo wp_json_encode($lang_for_cackle); ?><?php endif;?>});
                (function() {
                    var mc = document.createElement('script');
                    mc.type = 'text/javascript';
                    mc.async = true;
                    mc.src = 'https://cackle.me/widget.js';
                    var s = document.getElementsByTagName('script')[0]; s.parentNode.insertBefore(mc, s.nextSibling);
                })();
                //]]>
            </script>

        <?php
        }
    }
}


?>

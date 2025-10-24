<?php
// api.php
// 
// GeminiForge 的中央 API 代理。
// 處理 "rewrite_content", "generate_article", "generate_fb_post" 任務。
// 假設所有文件 (PDF, DOCX, TXT) 內容均由前端 (index.html) 處理完畢後，作為純文字傳入。

// ==== 1. 安全與CORS設定 ====
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// 預先處理 OPTIONS 請求
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    // 確保 Content-Type 標頭在 JSON 輸出前設定
    if (!headers_sent()) {
        header('Content-Type: application/json');
    }
    exit(0);
}

// 函數：用來回傳 JSON 錯誤並中止程式
function send_json_error($message, $http_code = 400) {
    if (!headers_sent()) {
        header('Content-Type: application/json');
    }
    http_response_code($http_code);
    echo json_encode(['error' => $message]);
    exit;
}

// ==== NEW: 輔助函數：抓取 URL 內容 ====
function fetch_url_content($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_USERAGENT, 'GeminiForge/1.0');
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $html = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code != 200 || !$html) {
        return "[無法抓取 URL 內容]";
    }
    
    // 基礎的 HTML 標籤移除
    $text = preg_replace('/<script\b[^>]*>.*?<\/script>/is', "", $html);
    $text = preg_replace('/<style\b[^>]*>.*?<\/style>/is', "", $text);
    $text = preg_replace('/<[^>]+>/', ' ', $text);
    $text = preg_replace('/\s+/', ' ', $text);
    
    return trim($text);
}

// ==== 2. 接收前端資料 ====
// 假設所有請求都是 JSON
$contentType = $_SERVER["CONTENT_TYPE"] ?? '';
if (strpos($contentType, 'application/json') === false) {
    send_json_error("不支援的 Content-Type: " . $contentType . "。此 API 僅接受 application/json。");
}

$json_data = file_get_contents('php://input');
$request_data = json_decode($json_data, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    send_json_error("JSON 請求解碼失敗: " . json_last_error_msg());
}
$requested_task = $request_data['task'] ?? '';


// ==== 3. 嚴格驗證輸入 (部分) ====
$api_key = $request_data['apiKey'] ?? '';
if (empty($api_key)) send_json_error('錯誤：API Key 未提供。');
if (empty($requested_task)) send_json_error('錯誤：未指定任務。');

// --- 處理 JSON 任務的後續邏輯 ---
$requested_model = $request_data['model'] ?? '';
$requested_language = $request_data['language'] ?? 'auto';

// 驗證模型 (白名單)
$allowed_models = ['gemini-2.5-pro', 'gemini-2.5-flash', 'gemini-2.5-flash-lite'];
$model_to_use = 'gemini-2.5-flash';
if (!empty($requested_model) && in_array($requested_model, $allowed_models)) {
    $model_to_use = $requested_model;
}

// 驗證語言 (白名單)
$allowed_languages = ['auto', '繁體中文', 'English', '日本語'];
$language_to_use = 'auto';
if (!empty($requested_language) && in_array($requested_language, $allowed_languages)) {
    $language_to_use = $requested_language;
}

// ==== 4. 準備呼叫 Gemini API ====
$api_url = sprintf(
    'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
    $model_to_use,
    $api_key
);

$full_prompt = '';
$generation_config = [];
$timeout = 120; // 預設超時

// 建立語言指令
$language_instruction = '';
if ($language_to_use !== 'auto') {
    $language_instruction = sprintf(
        "\n* 輸出語言：%s", // 改為列表項
        $language_to_use
    );
}

// ==== 5. 任務路由 (Task Routing) ====
switch ($requested_task) {
    // --- 任務 A: 文章改寫 ---
    case 'rewrite_content':
        $content_input = $request_data['content'] ?? ''; // content 欄位現在由 JS 確保已填入
        $input_type = $request_data['inputType'] ?? 'text';
        if (empty($content_input)) send_json_error('錯誤：改寫內容不可為空。');

        $mode = $request_data['mode'] ?? '重寫';
        $tone = $request_data['tone'] ?? '無';
        $tone_instruction = ($tone !== '無' && !empty(trim($tone))) ? sprintf("風格：%s", trim($tone)) : "風格：中性";
        $input_context = '';

        // ▼▼▼ 處理 URL 輸入 ▼▼▼
        if ($input_type === 'url') {
             $input_context = "請注意，以下內容是從一個網址抓取的，請基於此內容進行改寫：\n";
             $content_input = fetch_url_content($content_input); // 覆蓋 content_input
             if (strpos($content_input, "[無法抓取 URL 內容]") === 0) {
                 send_json_error("錯誤：無法抓取 URL 內容，請檢查網址或伺服器設定。");
             }
        }
        // ▲▲▲

        $prompt_template = <<<PROMPT
你是一位專業的文案編輯。請根據以下指令改寫提供的文字內容。
**指令：**
1.  改寫形式：%s
2.  %s
%s
---
[原文內容開始]
%s%s
[原文內容結束]
---
[改寫後的內容]
PROMPT;
        $full_prompt = sprintf($prompt_template, $mode, $tone_instruction, $language_instruction, $input_context, $content_input);
        $generation_config = ['responseMimeType' => 'text/plain', 'temperature' => 0.7];
        break;

    // --- 任務 B: 文章產生器 ---
    case 'generate_article':
        $title = $request_data['title'] ?? '';
        $keywords = $request_data['keywords'] ?? '';
        $audience = $request_data['audience'] ?? '';
        $length = $request_data['length'] ?? '中';
        $tone = $request_data['tone'] ?? '無';

        if (empty($title)) send_json_error('錯誤：文章標題不可為空。');
        
        $tone_instruction = ($tone !== '無' && !empty(trim($tone))) ? sprintf("* 寫作風格：%s", trim($tone)) : "* 寫作風格：專業且資訊豐富";
        $keyword_instruction = !empty(trim($keywords)) ? sprintf("* 核心關鍵字 (請圍繞這些詞彙)：%s", trim($keywords)) : "* 核心關鍵字：由 AI 決定";
        $audience_instruction = !empty(trim($audience)) ? sprintf("* 目標受眾：%s", trim($audience)) : "* 目標受眾：一般大眾";
        
        $length_map = [ '短' => '約 300-500 字', '中' => '約 800-1200 字', '長' => '約 1500-2000 字' ];
        $length_instruction = $length_map[$length] ?? '約 800-1200 字';
        
        $prompt_template = <<<PROMPT
任務：撰寫一篇高品質、結構完整的 SEO 部落格文章。
**輸入參數：**
* 文章標題：%s
* %s
* %s
* 目標長度：%s
* %s%s
**輸出格式要求：**
1.  **必須**使用 Markdown 格式撰寫。
2.  **必須**包含一個引人入勝的「引言」。
3.  **必須**包含數個邏輯清晰、帶有 `## H2 標題` 的「正文」段落 (若合適，請使用項目符號)。
4.  **必須**包含一個總結觀點的「結論」。
請直接開始撰寫文章：
PROMPT;

        $full_prompt = sprintf( $prompt_template, $title, $keyword_instruction, $audience_instruction, $length_instruction, $tone_instruction, $language_instruction );
        $generation_config = ['responseMimeType' => 'text/plain', 'temperature' => 0.8];
        $timeout = 300; // 5 分鐘
        
        if (($request_data['useWebSearch'] ?? false) === true) {
            $generation_config['tools'] = [ ["google_search" => new stdClass()] ];
            $new_prompt_instruction = "\n* **重要指令：** 必須使用 Google 搜尋來查找最新的即時資訊來撰寫這篇文章。\n";
            $full_prompt = str_replace( "**輸出格式要求：**", $new_prompt_instruction . "\n**輸出格式要求：**", $full_prompt );
        }
        break;

    // --- 任務 C: FB 廣告貼文 ---
    case 'generate_fb_post':
        $topic = $request_data['topic'] ?? '';
        if (empty($topic)) send_json_error('錯誤：貼文內容不可為空。');
        
        $form = $request_data['form'] ?? '廣告貼文';
        $audience = $request_data['audience'] ?? '';
        $length = $request_data['length'] ?? '中';
        $tone = $request_data['tone'] ?? '無';
        $useWebSearch = $request_data['useWebSearch'] ?? false;
        $includeImage = $request_data['includeImage'] ?? false;

        $tone_instruction = ($tone !== '無' && !empty(trim($tone))) ? sprintf("* 寫作風格：%s", trim($tone)) : "* 寫作風格：專業且具說服力";
        $audience_instruction = !empty(trim($audience)) ? sprintf("* 目標受眾：%s", trim($audience)) : "* 目標受眾：一般 Facebook 用戶";
        $length_map = [ '短' => '簡短 (約 1-3 句話)', '中' => '中等 (約 1-2 個段落)', '長' => '詳細 (多個段落，含項目符號)' ];
        $length_instruction = $length_map[$length] ?? '中等 (約 1-2 個段落)';
        $image_instruction = $includeImage ? "\n* **圖片提示：** 在文案結尾處，用 `[圖片提示：...]` 格式，提供一個適合這篇貼文的 AI 圖片生成提示詞。" : "";
        $web_instruction = $useWebSearch ? "\n* **網路搜尋：** 必須使用 Google 搜尋來查找與主題相關的最新資訊或賣點。" : "";

        $prompt_template = <<<PROMPT
你是一位頂尖的 Facebook 廣告文案專家 (Copywriter)。請根據以下要求，生成一篇高效、高轉換率的 Facebook 貼文。
**貼文要求：**
* 貼文主題/產品描述：%s
* 貼文形式：%s (如果是 '廣告貼文'，請加入強烈的行動呼籲 (Call to Action))
* %s
* %s
* %s%s%s%s
**輸出格式要求：**
1.  **開頭：** 使用一個引人注目的鉤子 (Hook) 來抓住注意力。
2.  **內容：** 清晰傳達價值主張，使用表情符號 (emoji) 來增加易讀性。
3.  **結尾：** 包含清晰的行動呼籲 (CTA) 和相關連結 (使用 `[範例連結]` 作為占位符)。
%s
請直接開始撰寫貼文內容：
PROMPT;

        $full_prompt = sprintf( $prompt_template, $topic, $form, $audience_instruction, $length_instruction, $tone_instruction, $web_instruction, $language_instruction, $image_instruction, $image_instruction );
        $generation_config = ['responseMimeType' => 'text/plain', 'temperature' => 0.9];
        $timeout = 180; // 3 分鐘

        if ($useWebSearch) {
            $generation_config['tools'] = [ ["google_search" => new stdClass()] ];
        }
        break;

    default:
        send_json_error('錯誤：未知的任務類型。');
}

// ==== 6. 建立 Payload ====
$data_payload = [
    'contents' => [['parts' => [['text' => $full_prompt]]]],
    'generationConfig' => $generation_config
];

if (isset($generation_config['tools'])) {
    $data_payload['tools'] = $generation_config['tools'];
    unset($data_payload['generationConfig']['tools']);
}

// ==== 7. cURL 執行 ====
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $api_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data_payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

$api_response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if (curl_errno($ch)) {
    $curl_error_message = curl_error($ch);
    $curl_error_code = curl_errno($ch);
    if ($curl_error_code == 28) {
         send_json_error('cURL 請求超時 (' . $timeout . ' 秒)。Gemini API 可能處理時間過長或網路延遲。', 504);
    } else {
         send_json_error('cURL 請求失敗 (' . $curl_error_code . '): ' . $curl_error_message, 500);
    }
}
curl_close($ch);

// ==== 8. 處理回應 ====
if ($http_code !== 200) {
    $error_details = json_decode($api_response, true);
    $error_message = $error_details['error']['message'] ?? 'Gemini API 錯誤，請檢查您的 API Key 或模型權限。';
    send_json_error($error_message, $http_code);
}

$response_data = json_decode($api_response, true);
$candidate = $response_data['candidates'][0] ?? null;
$gemini_output_text = null;

if ($candidate && isset($candidate['content']['parts'][0]['text'])) {
    $gemini_output_text = $candidate['content']['parts'][0]['text'];
} else if (isset($candidate['finishReason']) && $candidate['finishReason'] !== 'STOP') {
     send_json_error('Gemini 回應因安全或其他原因被中止 (' . $candidate['finishReason'] . ')。', 400);
}

if (is_null($gemini_output_text)) send_json_error('Gemini 未能產生有效內容 (回應為空)。', 500);

// ==== 9. 依任務回傳 ====
switch ($requested_task) {
    case 'rewrite_content':
        echo json_encode(['rewrittenText' => $gemini_output_text]);
        break;
    case 'generate_article':
        echo json_encode(['articleText' => $gemini_output_text]);
        break;
    case 'generate_fb_post':
        echo json_encode(['fbPostText' => $gemini_output_text]);
        break;
    default:
        send_json_error('錯誤：任務回傳失敗。', 500);
}

exit;
?>


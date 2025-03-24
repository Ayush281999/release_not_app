<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GitHubWebhookController extends Controller
{
    public function handleGitHubWebhook(Request $request)
    {
        Log::info("Webhook recasdeived: ");

        $payload = $request->all();

        // Decode the nested JSON string
        if (isset($payload['payload'])) {
            $decodedPayload = json_decode($payload['payload'], true);
        } else {
            $decodedPayload = $payload;
        }

        // Now you can access release details
        $release = $decodedPayload['release'] ?? null;
        $repository = $decodedPayload['repository'] ?? null;
        Log::info("Webhook received: " . json_encode($payload));
        $event = $request->header('X-GitHub-Event');
        Log::info("Event: " . $event);

        $owner = config('app.github_owner');
        $repo = config('app.github_repo');
        $token = config('app.github_token');
        $openAiKey = config('app.openai_api_key');

        if (!$owner || !$repo || !$token || !$openAiKey) {
            Log::error("GitHub or OpenAI API credentials are missing.");
            return response()->json(['error' => 'Missing credentials'], 400);
        }

        if ($event === 'release' && isset($release['tag_name'])) {
            $tag = $release['tag_name'];
            $this->generateReleaseNotes($owner, $repo, $token, $tag, $openAiKey);
        } elseif ($event === 'push' && isset($payload['ref']) && str_starts_with($payload['ref'], 'refs/tags/')) {
            $tag = str_replace('refs/tags/', '', $payload['ref']);
            $this->generateReleaseNotes($owner, $repo, $token, $tag, $openAiKey);
        } else {
            Log::info("Webhook received but no relevant action.");
        }

        return response()->json(['message' => 'Webhook received'], 200);
    }

    private function generateReleaseNotes($owner, $repo, $token, $newTag, $openAiKey)
    {
        // Get last release timestamp
        $lastRelease = $this->getLastGitHubReleaseDate($owner, $repo, $token, $newTag);
        $startTimestamp = $lastRelease ? $lastRelease['date'] : $this->getDefaultStartDate();
        $endTimestamp = now()->toIso8601String();

        Log::info("Fetching commits from $startTimestamp to $endTimestamp...");

        $commits = $this->fetchCommits($owner, $repo, $token, $startTimestamp, $endTimestamp);

        if (empty($commits)) {
            Log::info("No commits found in this period.");
            return;
        }

        // Process commit messages with AI
        $processedCommits = [];
        foreach ($commits as $commit) {
            $sha = $commit['sha'];
            $newMessage = $this->generateBetterCommitMessage($owner, $repo, $sha, $openAiKey);
            $processedCommits[] = $newMessage;
        }

        // Generate AI-based final summary
        $finalSummary = $this->generateImprovedSummary("Project Updates", $processedCommits, $openAiKey);

        // Format the release notes
        $releaseNotes = "### 📌 Release Notes ($startTimestamp to $endTimestamp)\n\n";
        $releaseNotes .= "#### 🔹 Summary of Updates\n" . $finalSummary . "\n";

        // Publish the release
        $this->createGitHubRelease($owner, $repo, $token, $newTag, $releaseNotes);
    }

    private function fetchCommits($owner, $repo, $token, $since, $until)
    {
        $response = Http::withToken($token)
            ->get("https://api.github.com/repos/$owner/$repo/commits", [
                'since' => $since,
                'until' => $until,
            ]);

        return $response->failed() ? [] : $response->json();
    }

    private function getLastGitHubReleaseDate($owner, $repo, $token, $currentTag)
    {
        $response = Http::withToken($token)
            ->get("https://api.github.com/repos/$owner/$repo/releases");

        if ($response->failed() || empty($response->json())) {
            return null;
        }

        foreach ($response->json() as $release) {
            if ($release['tag_name'] !== $currentTag) {
                return [
                    'name' => $release['tag_name'],
                    'date' => Carbon::parse($release['published_at'])->toIso8601String(),
                ];
            }
        }

        return null;
    }

    private function generateBetterCommitMessage($owner, $repo, $sha, $apiKey)
    {
        $response = Http::withToken(env('GITHUB_TOKEN'))
            ->get("https://api.github.com/repos/$owner/$repo/commits/$sha");

        if ($response->failed()) {
            return "Unknown commit changes (unable to fetch details).";
        }

        $commitData = $response->json();
        $files = $commitData['files'] ?? [];

        if (empty($files)) {
            return "Unknown commit changes (no files modified).";
        }

        $changes = [];
        foreach ($files as $file) {
            $filename = $file['filename'];
            $patch = substr($file['patch'] ?? '', 0, 500); // Limit to 500 chars for brevity

            $changes[] = "File: $filename\nChanges:\n$patch";
        }

        $text = implode("\n\n", $changes);
        $prompt = "Analyze the following code changes and generate a clear, professional commit message:\n\n$text";

        $response = Http::withHeaders([
            'Authorization' => "Bearer $apiKey",
            'Content-Type' => 'application/json',
        ])->post("https://api.openai.com/v1/chat/completions", [
            "model" => "gpt-4-turbo",
            "messages" => [
                ["role" => "system", "content" => "You are an AI assistant that generates well-written commit messages from code changes."],
                ["role" => "user", "content" => $prompt],
            ],
            "max_tokens" => 150,
        ]);

        return $response->json('choices.0.message.content') ?? "Generated commit message unavailable.";
    }

    // AI-powered summarization with better formatting
    private function generateImprovedSummary($category, $messages, $apiKey)
    {
        $text = implode("\n", $messages);
        $prompt = "You are an expert technical writer. Convert the following commit messages into a structured, well-written summary for the category '$category'. Keep it professional and concise:\n\n$text";

        $response = Http::withHeaders([
            'Authorization' => "Bearer $apiKey",
            'Content-Type' => 'application/json',
        ])->post("https://api.openai.com/v1/chat/completions", [
            "model" => "gpt-4-turbo",
            "messages" => [
                ["role" => "system", "content" => "You are an AI assistant specialized in generating clean, structured, and informative release notes."],
                ["role" => "user", "content" => $prompt],
            ],
            "max_tokens" => 300,
        ]);

        return $response->json('choices.0.message.content') ?? "No summary available.";
    }

    private function createGitHubRelease($owner, $repo, $token, $tag, $releaseNotes)
    {
        Http::withToken($token)->post("https://api.github.com/repos/$owner/$repo/releases", [
            'tag_name' => $tag,
            'name' => "Release $tag",
            'body' => $releaseNotes,
            'draft' => false,
            'prerelease' => false
        ]);
    }
}

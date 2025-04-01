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
        Log::info("Webhook received: " . json_encode($request->all()));

        $payload = $request->all();
        $event = $request->header('X-GitHub-Event');
        $owner = config('app.github_owner');
        $repo = config('app.github_repo');
        $token = config('app.github_token');
        $openAiKey = config('app.openai_api_key');

        if (!$owner || !$repo || !$token || !$openAiKey) {
            Log::error("GitHub or OpenAI API credentials are missing.");
            return response()->json(['error' => 'Missing credentials'], 400);
        }

        $tag = null;

        if ($event === 'release' && isset($payload['release']['tag_name'])) {
            $tag = $payload['release']['tag_name'];
        } elseif ($event === 'push' && isset($payload['ref']) && str_starts_with($payload['ref'], 'refs/tags/')) {
            $tag = str_replace('refs/tags/', '', $payload['ref']);
        }

        if (!$tag) {
            Log::info("Webhook received but no tag found.");
            return response()->json(['message' => 'No tag found'], 200);
        }

        Log::info("Processing GitHub event for tag: $tag");

        // Generate release notes and update GitHub release
        $this->generateReleaseNotes($owner, $repo, $token, $tag, $openAiKey);

        return response()->json(['message' => 'Webhook processed'], 200);
    }

    private function generateReleaseNotes($owner, $repo, $token, $newTag, $openAiKey)
    {
        $lastRelease = $this->getLastGitHubReleaseDate($owner, $repo, $token, $newTag);
        $startTimestamp = $lastRelease ? Carbon::parse($lastRelease['date'])->toIso8601String() : $this->getDefaultStartDate();
        $endTimestamp = now()->toIso8601String();

        Log::info("Fetching commits from $startTimestamp to $endTimestamp...");

        $commits = $this->fetchCommits($owner, $repo, $token, $startTimestamp, $endTimestamp);

        if (empty($commits)) {
            Log::info("No commits found for this period.");
            return;
        }

        // Process commit messages using AI
        $processedCommits = [];
        foreach ($commits as $commit) {
            $sha = $commit['sha'];
            $newMessage = $this->generateBetterCommitMessage($owner, $repo, $sha, $openAiKey);
            $processedCommits[] = $newMessage;
        }

        $finalSummary = $this->generateImprovedSummary("Project Updates", $processedCommits, $openAiKey);
        Log::info("Improved summary generated: " . $finalSummary);

        // Format the release notes
        $releaseNotes = "### 📌 Release Notes ($startTimestamp to $endTimestamp)\n\n";
        $releaseNotes .= "#### 🔹 Summary of Updates\n" . $finalSummary . "\n";

        // Check if release exists and update instead of creating a new one
        $existingReleaseId = $this->getExistingGitHubReleaseId($owner, $repo, $token, $newTag);

        if ($existingReleaseId) {
            $this->updateGitHubRelease($owner, $repo, $token, $existingReleaseId, $newTag, $releaseNotes);
        } else {
            $this->createGitHubRelease($owner, $repo, $token, $newTag, $releaseNotes);
        }
    }

    private function getExistingGitHubReleaseId($owner, $repo, $token, $tag)
    {
        $response = Http::withToken($token)
            ->get("https://api.github.com/repos/$owner/$repo/releases");

        if ($response->successful()) {
            foreach ($response->json() as $release) {
                if ($release['tag_name'] === $tag) {
                    return $release['id'];
                }
            }
        }

        return null;
    }

    private function updateGitHubRelease($owner, $repo, $token, $releaseId, $tag, $releaseNotes)
    {

        $response = Http::withToken($token)->patch("https://api.github.com/repos/$owner/$repo/releases/$releaseId", [
            'body' => $releaseNotes,
        ]);
    }
    private function fetchCommits($owner, $repo, $token, $since, $until)
    {

        $response = Http::withToken($token)
            ->get("https://api.github.com/repos/$owner/$repo/commits", [
                'since' => $since,
                'until' => $until,
            ]);

        return $response->json();
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

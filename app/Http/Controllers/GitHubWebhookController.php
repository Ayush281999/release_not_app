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
        Log::info("Webhook received: " . json_encode($payload));
        $event = $request->header('X-GitHub-Event');

        $owner = config('app.github_owner');
        $repo = config('app.github_repo');
        $token = config('app.github_token');
        $openAiKey = config('app.openai_api_key');

        if (!$owner || !$repo || !$token || !$openAiKey) {
            Log::error("GitHub or OpenAI API credentials are missing.");
            return response()->json(['error' => 'Missing credentials'], 400);
        }

        if ($event === 'release' && $payload['release']['tag_name']) {
            $tag = $payload['release']['tag_name'];
            Log::info("Release created: $tag");
            $this->generateReleaseNotes($owner, $repo, $token, $tag, $openAiKey);
        } elseif ($event === 'push' && isset($payload['ref']) && str_starts_with($payload['ref'], 'refs/tags/')) {
            $tag = str_replace('refs/tags/', '', $payload['ref']);
            Log::info("New tag pushed: $tag");
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

        Log::info("✅ Release notes published for tag $newTag.");
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
}

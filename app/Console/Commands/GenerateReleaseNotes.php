<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use DateTime;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class GenerateReleaseNotes extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'release:generate';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate automatic release notes based on commit messages.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $owner = config('app.github_owner');
        $repo = config('app.github_repo');
        $token = config('app.github_token');
        $openAiKey = config('app.openai_api_key');

        if (!$owner || !$repo || !$token || !$openAiKey) {
            $this->error("GitHub or OpenAI API credentials are missing in .env");
            return;
        }

        // Get the last release timestamp (exact date & time)
        $lastTag = $this->getLastGitHubReleaseDate($owner, $repo, $token);
        $startTimestamp = $lastTag ? $lastTag['date'] : $this->getDefaultStartDate();
        $endTimestamp = now()->toIso8601String(); // Current timestamp

        $this->info("Fetching commits from $startTimestamp to $endTimestamp...");

        // Fetch commits after the last release timestamp
        $response = Http::withToken($token)
            ->get("https://api.github.com/repos/$owner/$repo/commits", [
                'since' => $startTimestamp,
                'until' => $endTimestamp,
            ]);

        if ($response->failed()) {
            $this->error("Failed to fetch commits from GitHub.");
            return;
        }

        $commits = $response->json();

        // Process and improve commit messages
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

        // Save to GitHub releases and create a new tag
        $newTag = 'v' . now()->format('YmdHis');
        $this->createGitHubRelease($owner, $repo, $token, $newTag, $releaseNotes);

        $this->info("✅ Release notes published and new tag $newTag created.");
    }

    // Fetch the latest GitHub tag
    private function getLastGitHubReleaseDate($owner, $repo, $token)
    {
        $response = Http::withToken($token)
            ->get("https://api.github.com/repos/$owner/$repo/releases");

        if ($response->failed() || empty($response->json())) {
            return null; // No previous releases found
        }

        $latestRelease = $response->json()[0]; // Get the latest release

        if (empty($latestRelease['published_at'])) {
            return null; // No valid release timestamp found
        }

        return [
            'name' => $latestRelease['tag_name'],
            'date' => Carbon::parse($latestRelease['published_at'])->toIso8601String(), // Proper timestamp
        ];
    }

    // Determine the default start date dynamically
    private function getDefaultStartDate()
    {
        $installationDate = env('PACKAGE_INSTALLED_DATE', now()->subDays(30)->format('Y-m-d'));
        return Carbon::parse($installationDate)->addDays(15)->format('Y-m-d');
    }

    // Create a new GitHub release and tag
    private function createGitHubRelease($owner, $repo, $token, $tag, $releaseNotes)
    {

        $response = Http::withToken($token)->post("https://api.github.com/repos/$owner/$repo/releases", [
            'tag_name' => $tag,
            'name' => "Release $tag",
            'body' => $releaseNotes,
            'draft' => false,
            'prerelease' => false
        ]);

        if ($response->successful()) {
            $this->info("GitHub release created successfully with tag: $tag");
        } else {
            $this->error("Failed to create GitHub release. Response: " . $response->body());
        }
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

    // AI-powered commit message generator
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
}

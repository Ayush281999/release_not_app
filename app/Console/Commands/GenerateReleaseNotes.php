<?php

namespace App\Console\Commands;

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
        $owner = env('GITHUB_OWNER');
        $repo = env('GITHUB_REPO');
        $token = env('GITHUB_TOKEN');
        $openAiKey = env('OPENAI_API_KEY'); // OpenAI API key

        if (!$owner || !$repo || !$token || !$openAiKey) {
            $this->error("GitHub or OpenAI API credentials are missing in .env");
            return;
        }

        // Get commits from the last 10 days
        $since = now()->subDays(10)->toIso8601String();
        $response = Http::withToken($token)
            ->get("https://api.github.com/repos/$owner/$repo/commits", [
                'since' => $since,
            ]);

        if ($response->failed()) {
            $this->error("Failed to fetch commits from GitHub.");
            return;
        }

        $commits = $response->json();
        if (empty($commits)) {
            $this->info("No commits found in the last 10 days.");
            return;
        }

        // Categorize commits based on tags
        $categories = [
            'bug_fixes' => [],
            'features' => [],
            'changes' => [],
            'improvements' => [],
        ];

        foreach ($commits as $commit) {
            $message = $commit['commit']['message'];
            if (str_contains($message, '-bf')) {
                $categories['bug_fixes'][] = $message;
            } elseif (str_contains($message, '-fa')) {
                $categories['features'][] = $message;
            } elseif (str_contains($message, '-cm')) {
                $categories['changes'][] = $message;
            } elseif (str_contains($message, '-im')) {
                $categories['improvements'][] = $message;
            }
        }

        // Summarize each category using AI
        $summarizedNotes = [];
        foreach ($categories as $category => $messages) {
            if (!empty($messages)) {
                $summarizedNotes[$category] = $this->summarizeWithAI($messages, $openAiKey);
            }
        }

        // Generate final summary of all changes
        $allCommits = array_merge(...array_values($categories));
        $finalSummary = $this->summarizeWithAI($allCommits, $openAiKey, "Generate a final high-level summary of all changes.");

        // Generate the release note format
        $releaseNotes = "Release Notes for " . now()->subDays(10)->format('Y-m-d') . " to " . now()->format('Y-m-d') . ":\n\n";
        foreach ($summarizedNotes as $category => $summary) {
            $releaseNotes .= "**" . ucfirst(str_replace('_', ' ', $category)) . ":**\n";
            $releaseNotes .= "- $summary\n\n";
        }

        $releaseNotes .= "**Overall Summary:**\n";
        $releaseNotes .= "- $finalSummary\n";

        dd("Final Release Notes", $releaseNotes);

        // Save to a file (Optional)
        file_put_contents(storage_path('logs/release_notes.txt'), $releaseNotes);
        $this->info("Release notes saved to storage/logs/release_notes.txt");
    }

    // Function to summarize commit messages using AI
    private function summarizeWithAI($messages, $apiKey, $prompt = "Summarize the following commit messages in a structured way:")
    {
        $text = implode("\n", $messages);
        $response = Http::withHeaders([
            'Authorization' => "Bearer $apiKey",
            'Content-Type' => 'application/json',
        ])->post("https://api.openai.com/v1/chat/completions", [
            "model" => "gpt-4",
            "messages" => [
                ["role" => "system", "content" => $prompt],
                ["role" => "user", "content" => $text],
            ],
            "max_tokens" => 250,
        ]);

        return $response->json('choices.0.message.content') ?? "No summary available.";
    }
}

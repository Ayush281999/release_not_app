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

        if (!$owner || !$repo || !$token) {
            $this->error("GitHub API credentials are missing in .env");
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
        // dd($commits);
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
            dd(str_contains($message, '-bug fixed'));
            if (str_contains($message, '-bug fixed')) {
                $categories['bug_fixes'][] = $message;
            } elseif (str_contains($message, '-feature added')) {
                $categories['features'][] = $message;
            } elseif (str_contains($message, '-changes made')) {
                $categories['changes'][] = $message;
            } elseif (str_contains($message, '-improvement')) {
                $categories['improvements'][] = $message;
            }
        }
        // dd($categories);

        // Summarize each category
        $summarizedNotes = [];
        foreach ($categories as $category => $messages) {
            if (!empty($messages)) {
                $summarizedNotes[$category] = $this->summarizeCommits($messages);
            }
        }

        // Debugging: See the summarized commit messages before formatting
        dd("Ayush", $summarizedNotes);

        // Generate the release note format
        $releaseNotes = "Release Notes for " . now()->subDays(10)->format('Y-m-d') . " to " . now()->format('Y-m-d') . ":\n\n";

        foreach ($summarizedNotes as $category => $messages) {
            if (!empty($messages)) {
                $releaseNotes .= "**" . ucfirst(str_replace('_', ' ', $category)) . ":**\n";
                foreach ($messages as $msg) {
                    $releaseNotes .= "- $msg\n";
                }
                $releaseNotes .= "\n";
            }
        }

        // Output the release notes
        $this->info($releaseNotes);

        // Save to a file (Optional)
        file_put_contents(storage_path('logs/release_notes.txt'), $releaseNotes);

        $this->info("Release notes saved to storage/logs/release_notes.txt");
    }

    private function summarizeCommits($messages)
    {
        $summary = [];
        foreach ($messages as $message) {
            $cleanedMessage = trim(preg_replace('/[^a-zA-Z0-9\s]/', '', $message)); // Remove special characters
            $key = strtolower(explode(' ', $cleanedMessage, 3)[2] ?? $cleanedMessage); // Extract key phrase

            if (!isset($summary[$key])) {
                $summary[$key] = $cleanedMessage;
            } else {
                $summary[$key] .= ", " . $cleanedMessage;
            }
        }
        return array_values($summary);
    }
}

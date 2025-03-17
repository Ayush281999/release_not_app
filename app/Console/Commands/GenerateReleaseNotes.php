<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

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
        // Get commit messages from the last 10 days
        $commits = shell_exec("git log --since='10 days ago' --pretty=format:'%s'");
        $commitMessages = explode("\n", trim($commits));

        // Define categories
        $categories = [
            'Bug Fixes' => '-bug fixed',
            'Features Added' => '-feature added',
            'Changes Made' => '-changes made',
            'Improvements' => '-improvement',
        ];
        dd($commitMessages);
        // Categorize commit messages
        $categorizedCommits = [];
        foreach ($commitMessages as $commit) {
            foreach ($categories as $category => $tag) {
                if (strpos($commit, $tag) !== false) {
                    $categorizedCommits[$category][] = str_replace($tag, '', $commit);
                    break;
                }
            }
        }

        // Summarize commit messages
        foreach ($categorizedCommits as $category => $messages) {
            $categorizedCommits[$category] = $this->summarizeCommits($messages);
        }

        // Format release notes
        $releaseNotes = "Release Notes for " . now()->subDays(10)->toDateString() . " to " . now()->toDateString() . ":\n\n";
        foreach ($categorizedCommits as $category => $messages) {
            $releaseNotes .= "**$category:**\n";
            foreach ($messages as $message) {
                $releaseNotes .= "- " . trim($message) . "\n";
            }
            $releaseNotes .= "\n";
        }

        // Save release notes to a file
        File::put(storage_path('logs/release_notes.txt'), $releaseNotes);

        $this->info("Release notes generated successfully.");
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

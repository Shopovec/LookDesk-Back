<?PHP
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();

            $table->string('stripe_invoice_id')->unique();
            $table->string('stripe_subscription_id')->nullable()->index();

            $table->integer('amount_paid')->default(0); // в cents
            $table->string('currency', 10)->nullable();

            $table->string('status', 50)->nullable(); // paid / open / uncollectible etc.
            $table->timestamp('paid_at')->nullable();

            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();

            $table->string('hosted_invoice_url')->nullable();
            $table->string('invoice_pdf')->nullable();

            $table->json('raw')->nullable(); // на будущее
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_payments');
    }
};
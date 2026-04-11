<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->index('created_by');
            $table->index(['id', 'created_at']);           // для ORDER BY id DESC + возможные фильтры
        });

        // Переводы документов — самый важный индекс
        Schema::table('document_translations', function (Blueprint $table) {
            $table->index(['document_id', 'lang']);                    // для with() + whereHas/exists
            $table->index(['lang', 'title']);                          // для поиска по title
            $table->index('title');                                    // дополнительно
        });

        // Пивот-таблицы (many-to-many)
        Schema::table('document_category', function (Blueprint $table) {   // или как у тебя называется
            $table->index(['document_id', 'category_id']);
            $table->index('category_id');   // если фильтруем часто по категории
        });

        Schema::table('document_function', function (Blueprint $table) {
            $table->index(['document_id', 'function_id']);
            $table->index('function_id');
        });

        // Таблица просмотров (views) — предполагаю polymorphic
        Schema::table('views', function (Blueprint $table) {
            $table->index(['viewable_id', 'viewable_type', 'created_at']); // для withCount last 30 days
            // Если viewable_type всегда 'App\Models\Document', можно добавить:
            // $table->index(['viewable_id', 'created_at']);
        });

        // Категории переводы
        Schema::table('category_translations', function (Blueprint $table) {
            $table->index(['category_id', 'lang']);
        });

        // 5) chat_messages
        if (Schema::hasTable('chat_messages')) {
            Schema::table('chat_messages', function (Blueprint $table) {
                $table->index(['role', 'created_at'], 'cm_role_created_at_idx');
            });
        }


        // chat_sessions
        if (Schema::hasTable('chat_sessions')) {
            Schema::table('chat_sessions', function (Blueprint $table) {
                $table->index(['created_at', 'user_id'], 'chat_sessions_created_at_user_id_idx');
            });
        }

        // chat_messages
        if (Schema::hasTable('chat_messages')) {
            Schema::table('chat_messages', function (Blueprint $table) {
                $table->index(['role', 'created_at'], 'chat_messages_role_created_at_idx');
            });
        }

        // subscriptions
        if (Schema::hasTable('subscriptions')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->index(['status', 'created_at'], 'subscriptions_status_created_at_idx');
                $table->index(['user_id', 'status'], 'subscriptions_user_id_status_idx');
            });
        }

        // ocr_scans
        if (Schema::hasTable('ocr_scans')) {
            Schema::table('ocr_scans', function (Blueprint $table) {
                $table->index('user_id', 'ocr_scans_user_id_idx');
            });
        }

        // users
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                $table->index('role_id', 'users_role_id_idx');
                $table->index('created_at', 'users_created_at_idx');
                $table->index('deleted_at', 'users_deleted_at_idx');
            });
        }

        // pivot category_document
        if (Schema::hasTable('category_document')) {
            Schema::table('category_document', function (Blueprint $table) {
                $table->index(['category_id', 'document_id'], 'cd_category_id_document_id_idx');
                $table->index(['document_id', 'category_id'], 'cd_document_id_category_id_idx');
            });
        }

        // pivot document_function
        if (Schema::hasTable('document_function')) {
            Schema::table('document_function', function (Blueprint $table) {
                $table->index(['function_id', 'document_id'], 'df_function_id_document_id_idx');
            });
        }

        Schema::table('category_translations', function (Blueprint $table) {
            $table->index(['category_id', 'lang'], 'ct_category_id_lang_idx');
        });

        Schema::table('function_translations', function (Blueprint $table) {
            $table->index(['function_id', 'lang'], 'ft_function_id_lang_idx');
        });

        Schema::table('user_function', function (Blueprint $table) {
            $table->index(['user_id', 'function_id'], 'uf_user_id_function_id_idx');
            $table->index(['function_id', 'user_id'], 'uf_function_id_user_id_idx');
        });

    }

    public function down(): void
    {
        if (Schema::hasTable('document_translations')) {
            Schema::table('document_translations', function (Blueprint $table) {
                $table->dropIndex('dt_document_id_lang_idx');
                $table->dropIndex('dt_lang_title_idx');
            });

            try {
                DB::statement('ALTER TABLE document_translations DROP INDEX dt_title_content_fulltext');
            } catch (\Throwable $e) {
            }
        }

        if (Schema::hasTable('document_views')) {
            Schema::table('document_views', function (Blueprint $table) {
                $table->dropIndex('dv_document_id_created_at_idx');
            });
        }

        if (Schema::hasTable('category_document')) {
            Schema::table('category_document', function (Blueprint $table) {
                $table->dropIndex('cd_document_id_category_id_idx');
                $table->dropIndex('cd_category_id_document_id_idx');
            });
        }

        if (Schema::hasTable('document_function')) {
            Schema::table('document_function', function (Blueprint $table) {
                $table->dropIndex('df_document_id_function_id_idx');
                $table->dropIndex('df_function_id_document_id_idx');
            });
        }

        if (Schema::hasTable('chat_messages')) {
            Schema::table('chat_messages', function (Blueprint $table) {
                $table->dropIndex('cm_role_created_at_idx');
            });
        }
    }
};
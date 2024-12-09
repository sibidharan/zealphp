<style>
        /* Reset some default styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        /* Set the overall layout using Flexbox */
        body {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            font-family: Arial, sans-serif;
        }

        /* Header styling */
        header {
            background-color: #4CAF50;
            color: white;
            padding: 20px;
            text-align: center;
        }

        /* Container for side panel and main content */
        .container {
            flex: 1;
            display: flex;
        }

        /* Side Panel styling */
        aside {
            width: 250px;
            background-color: #f4f4f4;
            padding: 20px;
            border-right: 1px solid #ddd;
        }

        /* Main Content styling */
        main {
            flex: 1;
            padding: 20px;
        }

        /* Footer styling */
        footer {
            background-color: #333;
            color: white;
            text-align: center;
            padding: 15px;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .container {
                flex-direction: column;
            }

            aside {
                width: 100%;
                border-right: none;
                border-bottom: 1px solid #ddd;
            }
        }
    </style>
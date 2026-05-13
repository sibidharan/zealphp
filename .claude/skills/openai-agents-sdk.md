# OpenAI Agents SDK - Comprehensive Knowledge Base

## Overview

The OpenAI Agents SDK is a lightweight yet powerful Python framework for building multi-agent workflows. It's described as "an upgrade of our previous experimentation for agents, Swarm" with "a very small set of primitives."

**Repository**: https://github.com/openai/openai-agents-python
**Documentation**: https://openai.github.io/openai-agents-python/
**Python Version**: 3.9+

### Design Philosophy

- Minimal primitives for quick learning
- Python-native orchestration (no new abstractions to learn)
- Production-ready with built-in tracing
- Provider-agnostic (supports OpenAI + 100+ other LLMs)
- Pydantic-powered validation

---

## Installation

```bash
# Basic installation
pip install openai-agents

# With voice support
pip install 'openai-agents[voice]'

# With Redis session support
pip install 'openai-agents[redis]'
```

---

## Core Primitives

The SDK is built on **four main primitives**:

### 1. Agents

**Definition**: LLMs configured with instructions, tools, guardrails, and handoffs.

**Key Parameters**:

```python
from agents import Agent

agent = Agent(
    # Core config
    name="AgentName",
    instructions="System prompt or callable",
    model="gpt-4.1",  # defaults to gpt-4.1 if unspecified
    model_settings={...},  # temperature, top_p, etc.

    # Capabilities
    tools=[...],  # List of callable functions
    mcp_servers=[...],  # Model Context Protocol servers
    handoffs=[...],  # Sub-agents for delegation
    output_type=OutputSchema,  # Structured output (Pydantic/dataclass)

    # Safety & control
    input_guardrails=[...],  # Pre-execution validation
    output_guardrails=[...],  # Post-execution validation
    tool_use_behavior=...,  # Stop early or continue after tools
    reset_tool_choice=True,  # Prevent infinite tool loops
)
```

**Instructions**: Can be:
- Static string
- Callable function `(context, agent) -> str` for dynamic prompts

**Key Methods**:
- `get_system_prompt()`: Resolves instructions dynamically
- `get_all_tools()`: Aggregates tools from direct lists and MCP servers
- `clone()`: Creates shallow copy with modified parameters
- `as_tool()`: Transforms agent into reusable tool for other agents

---

### 2. Handoffs

**Definition**: Mechanism for agents to delegate tasks to other specialized agents.

**How it Works**:
1. LLM decides to invoke a handoff tool based on conversation context
2. The `on_invoke_handoff` function processes the handoff with LLM's arguments
3. Input validation occurs if an input type is specified
4. Control transfers to target agent, which continues processing

**Key Features**:
- **Input Filtering**: Optional filters can modify conversation history before next agent receives it
- **Conditional Activation**: Handoffs can be dynamically enabled/disabled
- **History Management**: Supports nesting handoff history for summarized context

**Pattern Example**:
```python
from agents import Agent

# Specialized agents
math_tutor = Agent(name="MathTutor", instructions="Help with math")
history_tutor = Agent(name="HistoryTutor", instructions="Help with history")

# Triage agent with handoffs
triage_agent = Agent(
    name="TriageAgent",
    instructions="Route questions to appropriate specialist",
    handoffs=[math_tutor, history_tutor]
)
```

---

### 3. Guardrails

**Definition**: Validation checks integrated into agent execution.

**Two Types**:
1. **Input Guardrails**: Execute before/alongside agent startup (e.g., detect off-topic)
2. **Output Guardrails**: Run on final agent output to verify criteria

**GuardrailFunctionOutput**:
```python
from agents import GuardrailFunctionOutput

return GuardrailFunctionOutput(
    output_info={"reason": "Off-topic"},  # Optional metadata
    tripwire_triggered=True  # When True, halts execution
)
```

**Decorator Usage**:

```python
from agents import input_guardrail, output_guardrail, RunContextWrapper, Agent

# Input guardrail
@input_guardrail(name="TopicCheck", run_in_parallel=False)
async def check_input(
    context: RunContextWrapper,
    agent: Agent,
    agent_input: str | list
) -> GuardrailFunctionOutput:
    # Validate input
    if is_off_topic(agent_input):
        return GuardrailFunctionOutput(
            tripwire_triggered=True,
            output_info={"message": "Off-topic request"}
        )
    return GuardrailFunctionOutput(tripwire_triggered=False)

# Output guardrail
@output_guardrail(name="OutputValidation")
async def check_output(
    context: RunContextWrapper,
    agent: Agent,
    agent_output: Any
) -> GuardrailFunctionOutput:
    # Validate output
    return GuardrailFunctionOutput(tripwire_triggered=False)
```

**Key Points**:
- Guardrails support both sync and async execution
- Input guardrails can run in parallel (default) or sequentially
- Tripwire triggers `InputGuardrailTripwireTriggered` exception

---

### 4. Sessions

**Definition**: Automatic conversation history management across agent runs.

**Available Implementations**:
- `Session`: Base session class
- `SessionABC`: Abstract base for custom implementations
- `SQLiteSession`: Persistent storage using SQLite
- `OpenAIConversationsSession`: Integration with OpenAI's conversation API

**Usage**:
```python
from agents import Runner, SQLiteSession

session = SQLiteSession(session_id="user_123")
result = await Runner.run(agent, input="Hello", session=session)
```

---

## Agent Execution Loop

### Runner Class

The `Runner` provides three entry points:

```python
from agents import Runner

# Async execution
result = await Runner.run(agent, input="...", context=custom_context)

# Sync wrapper
result = Runner.run_sync(agent, input="...")

# Streaming mode
async for event in Runner.run_streamed(agent, input="...").stream_events():
    print(event)
```

### Execution Flow

The agent runs in a loop until final output is generated:

**Phase 1: Initialization**
- Combine session history with user input
- Set up guardrail validators

**Phase 2: Turn Execution** (repeats)
1. **Guardrail Validation** (first turn only) - Input guardrails execute
2. **Model Invocation** - Agent receives system prompt, input items, tools, handoffs
3. **Response Processing** - Parse for tool calls, handoffs, or final outputs

**Phase 3: Handling Results**
- **Tool Calls**: Execute tools, feed results back into loop
- **Handoffs**: Transition to new agent, continue looping
- **Final Output**: Run output guardrails, return `RunResult`

**Control Structures**:
- **Max Turns Protection**: Raises `MaxTurnsExceeded` if limit exceeded
- **Session Management**: Accumulates conversation history
- **Streaming**: Emits granular `StreamEvent` objects during execution

---

## Tools System

### FunctionTool Architecture

Tools enable agents to perform actions beyond language generation.

**Supported Tool Types**:
- Function-based tools
- File search
- Web search
- Computer control
- MCP servers
- Code execution (CodeInterpreterTool)
- Shell commands (ShellTool, LocalShellTool)
- Image generation (ImageGenerationTool)
- Patch application (ApplyPatchTool)

### FunctionTool Class

```python
from agents import FunctionTool

tool = FunctionTool(
    name="tool_name",
    description="What this tool does",
    params_json_schema={...},  # Parameter structure
    on_invoke_tool=async_callback,  # Execution function
    strict_json_schema=True,  # Recommended for reliability
    is_enabled=True  # Boolean or callable for dynamic enable/disable
)
```

### Automatic Schema Generation

The `@function_tool` decorator generates tool schemas automatically:

```python
from agents import function_tool

@function_tool
def get_weather(location: str, units: str = "celsius") -> str:
    """Get weather for a location.

    Args:
        location: City name
        units: Temperature units (celsius or fahrenheit)
    """
    return f"Weather in {location}: 20°{units[0].upper()}"

# Agent can now use this tool
agent = Agent(name="WeatherBot", tools=[get_weather])
```

**Schema Generation Process**:
1. Parse function signatures to create JSON parameter schemas
2. Extract descriptions from docstrings (auto-detecting style)
3. Document argument descriptions from docstring content

### Tool Execution Pattern

1. Parse LLM-provided JSON input
2. Validate against parameter schema
3. Convert parsed data to function arguments
4. Invoke function (handles both sync and async)
5. Return structured output or invoke error handling

---

## RunContext and Custom Context

### RunContextWrapper

```python
from agents import RunContextWrapper

# Custom context type
class MyContext:
    def __init__(self, user_id: str, db_connection):
        self.user_id = user_id
        self.db = db_connection

# Pass to Runner
context = MyContext(user_id="123", db_connection=db)
result = await Runner.run(agent, input="...", context=context)
```

**Important**: Contexts are NOT passed to the LLM. They're a way to pass dependencies and data to your code (tools, callbacks, hooks).

**Accessing Context in Tools**:

```python
from agents import function_tool, RunContextWrapper

@function_tool
def save_data(context: RunContextWrapper[MyContext], data: str) -> str:
    # Access custom context
    user_id = context.context.user_id
    db = context.context.db

    # Use it
    db.save(user_id, data)
    return "Saved"
```

### Usage Tracking

The `RunContextWrapper` also contains usage metrics:
```python
result = await Runner.run(agent, input="...")
print(result.context.usage)  # Token consumption metrics
```

---

## Advanced Patterns

### Dynamic Instructions

Instructions can be callable for context-aware prompts:

```python
def build_instructions(context: RunContextWrapper, agent: Agent) -> str:
    user_data = context.context.user_data
    return f"You are helping {user_data['name']}. Their preferences: {user_data['prefs']}"

agent = Agent(
    name="PersonalAssistant",
    instructions=build_instructions
)
```

### Agent as Tool

Transform agents into reusable tools:

```python
specialist_agent = Agent(name="Specialist", instructions="...")

# Use as tool in another agent
main_agent = Agent(
    name="Main",
    tools=[
        specialist_agent.as_tool(
            tool_name="ConsultSpecialist",
            tool_description="Consult the specialist for complex queries"
        )
    ]
)
```

### Multi-Agent Orchestration

```python
# Create specialized agents
billing_agent = Agent(name="Billing", instructions="Handle billing")
tech_support = Agent(name="TechSupport", instructions="Handle tech issues")
account_agent = Agent(name="Account", instructions="Handle account management")

# Triage agent routes to specialists
triage = Agent(
    name="Triage",
    instructions="Route customer requests to appropriate department",
    handoffs=[billing_agent, tech_support, account_agent]
)

# Execute
result = await Runner.run(triage, input="I have a billing question")
# Triage automatically hands off to billing_agent
```

### Guardrail Agent Pattern

Use a separate agent for validation:

```python
from agents import Agent, input_guardrail, Runner

validation_agent = Agent(
    name="Validator",
    instructions="Check if input is appropriate for homework help",
    output_type=ValidationOutput  # Pydantic model
)

@input_guardrail(name="HomeworkCheck")
async def validate_homework(context, agent, agent_input):
    # Run validation agent
    result = await Runner.run(validation_agent, agent_input, context=context.context)
    output = result.final_output_as(ValidationOutput)

    return GuardrailFunctionOutput(
        tripwire_triggered=not output.is_homework_related,
        output_info={"reason": output.reason}
    )

# Main agent with guardrail
homework_helper = Agent(
    name="HomeworkHelper",
    input_guardrails=[validate_homework]
)
```

---

## Output Types and Structured Data

### Pydantic Models

```python
from pydantic import BaseModel, Field
from agents import Agent

class WeatherReport(BaseModel):
    location: str = Field(..., description="City name")
    temperature: float = Field(..., description="Temperature in celsius")
    conditions: str = Field(..., description="Weather conditions")

agent = Agent(
    name="WeatherBot",
    instructions="Provide weather reports in structured format",
    output_type=WeatherReport
)

result = await Runner.run(agent, input="Weather in Paris")
weather = result.final_output_as(WeatherReport)
print(weather.temperature)  # Type-safe access
```

### Dataclasses

```python
from dataclasses import dataclass

@dataclass
class TaskList:
    tasks: list[str]
    priority: str

agent = Agent(name="TaskManager", output_type=TaskList)
```

---

## Streaming

### Stream Events

```python
from agents import Runner

result = Runner.run_streamed(agent, input="Write a story")

async for event in result.stream_events():
    if event.type == "raw_response_event":
        print(event.data.delta, end="")  # Stream text deltas
```

**Event Types**:
- `raw_response_event`: Raw model output chunks
- `tool_call_event`: Tool invocation
- `handoff_event`: Agent handoff
- `final_output_event`: Final structured output

---

## Tracing

Built-in tracing for observability:

```python
from agents import trace, gen_trace_id

trace_id = gen_trace_id()

with trace("Agent Workflow", trace_id=trace_id):
    result = await Runner.run(agent, input="...")
    print(f"View trace: https://platform.openai.com/traces/trace?trace_id={trace_id}")
```

**Supported Platforms**:
- OpenAI Platform
- Logfire
- AgentOps
- Braintrust

---

## Key Imports

```python
# Core
from agents import Agent, Runner

# Tools
from agents import function_tool, FunctionTool
from agents import ShellTool, LocalShellTool, CodeInterpreterTool
from agents import WebSearchTool, ImageGenerationTool

# Guardrails
from agents import input_guardrail, output_guardrail
from agents import GuardrailFunctionOutput
from agents import InputGuardrailTripwireTriggered  # Exception

# Context
from agents import RunContextWrapper

# Sessions
from agents import Session, SQLiteSession, OpenAIConversationsSession

# Tracing
from agents import trace, gen_trace_id

# Utilities
from agents import run_demo_loop, enable_verbose_stdout_logging
from agents import set_default_openai_key, set_default_openai_client
```

---

## Best Practices

### 1. Instructions
- Be specific and actionable
- Use dynamic instructions for context-aware behavior
- Include examples in instructions for better performance

### 2. Tools
- Use descriptive tool names and descriptions
- Enable `strict_json_schema` for reliability
- Implement proper error handling in tool functions
- Use `is_enabled` callable for dynamic tool availability

### 3. Guardrails
- Use input guardrails for early validation (save tokens)
- Run independent guardrails in parallel (default)
- Use sequential guardrails when order matters
- Provide clear error messages in `output_info`

### 4. Handoffs
- Create focused, specialized agents
- Use clear handoff descriptions for routing
- Filter conversation history when needed
- Limit handoff depth to avoid complexity

### 5. Sessions
- Use persistent sessions (SQLite) for production
- Clear sessions when starting new conversations
- Be mindful of session size (token limits)

### 6. Output Types
- Use Pydantic models for structured output
- Provide field descriptions for better LLM understanding
- Validate output in output guardrails

### 7. Context
- Use custom context for dependencies (DB, APIs, user data)
- Never put sensitive data in LLM-visible areas
- Access context in tools, not in instructions

### 8. Error Handling
- Wrap Runner calls in try-except
- Handle `InputGuardrailTripwireTriggered` specifically
- Handle `MaxTurnsExceeded` for loop protection
- Implement tool failure callbacks

---

## Common Patterns

### Pattern 1: Triage + Specialists

```python
specialist_a = Agent(name="SpecialistA", instructions="Handle A")
specialist_b = Agent(name="SpecialistB", instructions="Handle B")

triage = Agent(
    name="Triage",
    instructions="Route to appropriate specialist",
    handoffs=[specialist_a, specialist_b]
)
```

### Pattern 2: Guardrail Agent

```python
validation_agent = Agent(name="Validator", output_type=ValidationSchema)

@input_guardrail()
async def validate(context, agent, input):
    result = await Runner.run(validation_agent, input)
    return GuardrailFunctionOutput(
        tripwire_triggered=not result.final_output.is_valid
    )
```

### Pattern 3: Tool + Agent Combo

```python
@function_tool
def search_data(query: str) -> str:
    return f"Search results for {query}"

analysis_agent = Agent(
    name="Analyzer",
    instructions="Analyze search results",
    output_type=AnalysisResult
)

main_agent = Agent(
    name="Main",
    tools=[search_data, analysis_agent.as_tool()]
)
```

### Pattern 4: Session-Based Conversation

```python
session = SQLiteSession(session_id=user_id)

while True:
    user_input = input("You: ")
    result = await Runner.run(agent, user_input, session=session)
    print(f"Agent: {result.final_output}")
```

---

## Anti-Patterns to Avoid

### ❌ Don't: Put business logic in instructions
```python
# Bad
instructions = "Connect to database at mysql://... and query..."
```

### ✅ Do: Use tools and context
```python
# Good
@function_tool
def query_db(context: RunContextWrapper, query: str):
    return context.context.db.execute(query)
```

### ❌ Don't: Infinite tool loops
```python
# Bad - no reset_tool_choice
agent = Agent(tools=[recursive_tool], reset_tool_choice=False)
```

### ✅ Do: Enable reset or limit turns
```python
# Good
agent = Agent(tools=[tool], reset_tool_choice=True)
result = await Runner.run(agent, max_turns=10)
```

### ❌ Don't: Expose sensitive data to LLM
```python
# Bad
instructions = f"API Key: {api_key}"
```

### ✅ Do: Use context
```python
# Good
@function_tool
def call_api(context: RunContextWrapper):
    api_key = context.context.api_key  # Not visible to LLM
```
